<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * SEC-AUTH-02A — durcissements de l'authentification Bearer existante
 * (sans changer son architecture) :
 *  - SEC-008 : mot de passe explicitement typé "string" (login / register) ;
 *  - SEC-006 : limiteurs dédiés login / register (5 / minute, IP + email) ;
 *  - SEC-009 : /api/user soumis au contrôle d'inactivité, logout non.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-password-123';

    private const NOW = '2026-09-15 12:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush(); // rate limiter counters must never leak between tests

        parent::tearDown();
    }

    private function login(array $payload, string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/login', $payload);
    }

    private function register(array $payload, string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/register', $payload);
    }

    // ---------------------------------------------------------------- SEC-008

    public function test_login_with_an_array_password_is_a_validation_error_not_a_crash(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->login(['email' => $user->email, 'password' => ['x' => self::PASSWORD]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_register_with_an_array_password_is_a_validation_error_not_a_crash(): void
    {
        $this->register([
            'name' => 'Array Password',
            'email' => 'array-password@example.test',
            'password' => ['aaaaaaaa', 'bbbbbbbb'],
            'password_confirmation' => ['aaaaaaaa', 'bbbbbbbb'],
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'array-password@example.test']);
    }

    public function test_string_passwords_keep_the_current_register_and_login_behavior(): void
    {
        $this->register([
            'name' => 'String Password',
            'email' => 'string-password@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('email', 'string-password@example.test');

        // Current minimum length and confirmation rules are unchanged.
        $this->register([
            'name' => 'Short',
            'email' => 'short@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->login(['email' => 'string-password@example.test', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('message', 'Logged in');
    }

    // ---------------------------------------------------------------- SEC-006

    public function test_login_is_throttled_on_the_sixth_attempt_for_the_same_ip_and_email(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        // Email normalization: case and surrounding spaces share one bucket.
        $variants = [$user->email, strtoupper($user->email), ' '.$user->email.' ', $user->email, ucfirst($user->email)];

        foreach ($variants as $email) {
            $this->login(['email' => $email, 'password' => 'wrong-password'])->assertStatus(401);
        }

        $this->login(['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_login_buckets_are_independent_per_email_and_per_ip(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $other = User::factory()->create(['password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->login(['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(401);
        }
        $this->login(['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(429);

        // Same IP, another email: not throttled.
        $this->login(['email' => $other->email, 'password' => self::PASSWORD])->assertOk();

        // Same email, another IP: not throttled.
        $this->login(['email' => $user->email, 'password' => self::PASSWORD], '198.51.100.20')->assertOk();
    }

    public function test_malformed_emails_never_crash_the_login_limiter(): void
    {
        $payloads = [
            ['email' => ['a@example.test'], 'password' => 'x'],
            ['email' => ['nested' => ['a@example.test']], 'password' => 'x'],
            ['email' => null, 'password' => 'x'],
            ['password' => 'x'],
            ['email' => 12345, 'password' => 'x'],
        ];

        foreach ($payloads as $payload) {
            $this->login($payload)->assertStatus(422);
        }

        // All non-string emails from one IP share one bucket: the 6th is throttled, never a 500.
        $this->login(['email' => ['b@example.test'], 'password' => 'x'])->assertStatus(429);
    }

    public function test_register_is_throttled_separately_from_login(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->register([
                'name' => 'Flood',
                'email' => 'flood@example.test',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])->assertStatus($i === 0 ? 200 : 422);
        }

        $this->register([
            'name' => 'Flood',
            'email' => 'flood@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertStatus(429);

        // The login bucket for the same IP + email is untouched.
        $this->login(['email' => 'flood@example.test', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_malformed_emails_never_crash_the_register_limiter(): void
    {
        $this->register(['name' => 'X', 'email' => ['x@example.test'], 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_dedicated_limiters_only_apply_to_their_routes_and_crud_keeps_the_generic_limiter(): void
    {
        $routes = $this->app['router']->getRoutes();
        $middlewareFor = fn (string $method, string $uri) => $routes->match(Request::create($uri, $method))->gatherMiddleware();

        $this->assertContains('throttle:login', $middlewareFor('POST', '/api/login'));
        $this->assertContains('throttle:register', $middlewareFor('POST', '/api/register'));

        foreach ([['GET', '/api/expenses'], ['POST', '/api/expenses'], ['GET', '/api/budgets'], ['GET', '/api/user'], ['POST', '/api/logout']] as [$method, $uri]) {
            $middleware = $middlewareFor($method, $uri);
            $this->assertContains('api', $middleware, "$method $uri");
            $this->assertNotContains('throttle:login', $middleware, "$method $uri");
            $this->assertNotContains('throttle:register', $middleware, "$method $uri");
        }

        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 7; $i++) {
            $this->getJson('/api/expenses')->assertOk()->assertHeader('X-RateLimit-Limit', '60');
        }
    }

    // ---------------------------------------------------------------- SEC-009

    /**
     * @return array{0: User, 1: string}
     */
    private function userInactiveFor(int $minutes): array
    {
        Carbon::setTestNow(Carbon::parse(self::NOW));

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $user->forceFill(['last_activity' => now()->subMinutes($minutes)])->save();

        return [$user, $token];
    }

    public function test_user_endpoint_is_denied_after_more_than_1440_minutes_of_inactivity(): void
    {
        [$user, $token] = $this->userInactiveFor(1441);

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertStatus(401)->assertJsonMissingPath('email');
        $this->assertStringStartsWith('Session expir', (string) $response->json('message'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_user_endpoint_is_allowed_at_exactly_1440_minutes_of_inactivity(): void
    {
        [$user, $token] = $this->userInactiveFor(1440);

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->assertSame(self::NOW, $user->fresh()->getRawOriginal('last_activity'));
    }

    public function test_logout_still_revokes_an_inactive_token(): void
    {
        [$user, $token] = $this->userInactiveFor(1441);

        $this->withToken($token)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }
}
