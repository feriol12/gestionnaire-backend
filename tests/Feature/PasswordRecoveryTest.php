<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordResetController;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/*
 * AUTH3-03 — récupération de mot de passe (Password Broker Laravel) au-dessus
 * de l'authentification par session Sanctum (AUTH3-01).
 *
 * Même approche que SessionAuthenticationTest : vraie pile HTTP (CORS,
 * Sanctum stateful, cookies chiffrés, session, CSRF réellement vérifiée), avec
 * des « navigateurs » de test indépendants (un pot de cookies chacun).
 */
class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = 'http://localhost:5173';

    private const PASSWORD = 'secret-password-123';

    /** @var array<string, array<string, string>> cookie jars per test browser */
    private array $jars = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cors.allowed_origins' => [self::SPA]]);

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function browser(string $name, string $method, string $uri, array $data = [], bool $sendXsrf = true): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->unencryptedCookies = [];
        $jar = $this->jars[$name] ?? [];

        $headers = ['Origin' => self::SPA, 'Referer' => self::SPA.'/', 'Accept' => 'application/json'];
        if ($sendXsrf && isset($jar['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $jar['XSRF-TOKEN'];
        }

        $response = $this->withCredentials()->withUnencryptedCookies($jar)->json($method, $uri, $data, $headers);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($jar[$cookie->getName()]);
            } else {
                $jar[$cookie->getName()] = $cookie->getValue();
            }
        }
        $this->jars[$name] = $jar;

        return $response;
    }

    private function csrf(string $name): void
    {
        $this->browser($name, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
    }

    private function sessionLogin(string $name, string $email, string $password): TestResponse
    {
        $this->csrf($name);

        return $this->browser($name, 'POST', '/api/login', ['email' => $email, 'password' => $password]);
    }

    private function forgot(string $name, string $email, bool $sendXsrf = true): TestResponse
    {
        return $this->browser($name, 'POST', '/api/forgot-password', ['email' => $email], $sendXsrf);
    }

    private function reset(string $name, array $payload, bool $sendXsrf = true): TestResponse
    {
        return $this->browser($name, 'POST', '/api/reset-password', $payload, $sendXsrf);
    }

    private function resetPayload(string $token, string $email, string $password = 'brand-new-password-456'): array
    {
        return ['token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password];
    }

    // ---------------------------------------------------------------- forgot-password

    public function test_forgot_password_public_response_is_identical_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->csrf('A');

        $known = $this->forgot('A', $user->email);
        $unknown = $this->forgot('A', 'nobody-'.$user->id.'@example.test');
        $knownAgain = $this->forgot('A', $user->email); // broker throttle (60 s): still the same answer

        foreach ([$known, $unknown, $knownAgain] as $response) {
            $response->assertOk()->assertExactJson(['message' => PasswordResetController::LINK_REQUESTED]);
        }
        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame($known->getContent(), $knownAgain->getContent());

        // Internally only the existing account receives a link (once, broker throttle).
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_reset_link_points_to_the_spa_with_token_and_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'link+test@example.test']);
        $this->csrf('A');
        $this->forgot('A', $user->email)->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $url);
            $this->assertNotSame(rtrim(config('app.url'), '/'), config('app.frontend_url'));
            $this->assertSame($notification->token, $query['token'] ?? null);
            $this->assertSame('link+test@example.test', $query['email'] ?? null);

            return true;
        });
    }

    public function test_forgot_password_validates_the_email_format_only(): void
    {
        $this->csrf('A');

        $this->forgot('A', 'not-an-email')->assertStatus(422)->assertJsonValidationErrors('email');
        $this->browser('A', 'POST', '/api/forgot-password', ['email' => ['x@example.test']])->assertStatus(422);
        $this->browser('A', 'POST', '/api/forgot-password', [])->assertStatus(422);
    }

    // ---------------------------------------------------------------- reset-password

    public function test_valid_token_resets_the_password_without_logging_in_or_creating_tokens(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $rememberBefore = $user->getRememberToken();
        $token = Password::broker()->createToken($user);
        $this->csrf('A');

        $this->reset('A', $this->resetPayload($token, $user->email))
            ->assertOk()
            ->assertExactJson(['message' => PasswordResetController::RESET_DONE]);

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password-456', $user->password));
        $this->assertNotSame($rememberBefore, $user->getRememberToken());
        $this->assertSame(0, DB::table('password_reset_tokens')->count(), 'the token is consumed');

        // No automatic login, no personal access token.
        $this->browser('A', 'GET', '/api/user')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());

        // Old password refused, new password accepted (session login, still no PAT).
        $this->sessionLogin('B', $user->email, self::PASSWORD)->assertUnauthorized();
        $this->sessionLogin('C', $user->email, 'brand-new-password-456')->assertOk()->assertJsonMissingPath('token');
        $this->browser('C', 'GET', '/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_invalid_expired_reused_and_mismatched_tokens_get_the_same_safe_rejection(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $other = User::factory()->create(['password' => self::PASSWORD]);
        $this->csrf('A');
        $expected = ['message' => PasswordResetController::INVALID_LINK, 'errors' => ['token' => [PasswordResetController::INVALID_LINK]]];

        // Wrong token.
        Password::broker()->createToken($user);
        $wrong = $this->reset('A', $this->resetPayload('not-the-real-token', $user->email));

        // Token of one account presented with another account's email, and with an unknown email.
        $token = Password::broker()->createToken($user);
        $mismatch = $this->reset('A', $this->resetPayload($token, $other->email));
        $unknown = $this->reset('A', $this->resetPayload($token, 'nobody@example.test'));

        // Reused token: first use succeeds, second fails.
        $this->reset('A', $this->resetPayload($token, $user->email, 'first-new-password-1'))->assertOk();
        $reused = $this->reset('A', $this->resetPayload($token, $user->email, 'second-new-password-2'));

        // Expired token (broker expiry: 60 minutes).
        $expiring = Password::broker()->createToken($other);
        $this->travel(61)->minutes();
        $expired = $this->reset('A', $this->resetPayload($expiring, $other->email));
        $this->travelBack();

        foreach (compact('wrong', 'mismatch', 'unknown', 'reused', 'expired') as $case => $response) {
            $response->assertStatus(422)->assertExactJson($expected);
            $this->assertStringNotContainsString($token, $response->getContent(), "$case leaks the token");
        }

        $this->assertTrue(Hash::check('first-new-password-1', $user->fresh()->password));
        $this->assertTrue(Hash::check(self::PASSWORD, $other->fresh()->password));
    }

    public function test_reset_keeps_the_current_password_rules(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = Password::broker()->createToken($user);
        $this->csrf('A');

        $this->reset('A', ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-password-456', 'password_confirmation' => 'different-456'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset('A', $this->resetPayload($token, $user->email, 'short'))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset('A', ['token' => $token, 'email' => $user->email, 'password' => ['x' => 'y'], 'password_confirmation' => ['x' => 'y']])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset('A', ['email' => $user->email, 'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456'])
            ->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    // ---------------------------------------------------------------- CSRF / rate limiting

    public function test_recovery_endpoints_require_the_csrf_token_for_the_spa(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = Password::broker()->createToken($user);
        $this->csrf('A');

        $this->forgot('A', $user->email, false)->assertStatus(419);
        $this->reset('A', $this->resetPayload($token, $user->email), false)->assertStatus(419);

        Notification::assertNothingSent();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_dedicated_limiters_throttle_recovery_the_same_way_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->csrf('A');

        foreach ([$user->email, 'nobody@example.test'] as $email) {
            for ($i = 0; $i < 3; $i++) {
                $this->forgot('A', $email)->assertOk();
            }
            // Same status and message whether the account exists or not (the body
            // also carries a debug trace when APP_DEBUG=true locally, hence no exact match).
            $this->forgot('A', $email)->assertStatus(429)->assertJsonPath('message', 'Too Many Attempts.');
        }

        for ($i = 0; $i < 5; $i++) {
            $this->reset('A', $this->resetPayload('wrong-token', $user->email))->assertStatus(422);
        }
        $this->reset('A', $this->resetPayload('wrong-token', $user->email))->assertStatus(429);
    }

    public function test_recovery_routes_are_public_and_use_dedicated_limiters(): void
    {
        $router = $this->app['router'];
        foreach (['/api/forgot-password' => 'forgot-password', '/api/reset-password' => 'reset-password'] as $uri => $limiter) {
            $middleware = $router->getRoutes()->match(\Illuminate\Http\Request::create($uri, 'POST'))->gatherMiddleware();
            $this->assertContains('throttle:'.$limiter, $middleware);
            $this->assertContains('api', $middleware); // stateful SPA middleware (session + CSRF) and generic limiter
            $this->assertNotContains('auth:sanctum', $middleware);
        }
    }

    // ---------------------------------------------------------------- session / PAT policy

    /**
     * Documents the reset policy: browser sessions of the account are closed
     * by Sanctum's AuthenticateSession (password hash check) on their next
     * request. AUTH3-05 (closes A303-F1): the account's personal access tokens
     * are revoked by a password reset as well.
     */
    public function test_policy_after_reset_closes_browser_sessions_and_revokes_personal_access_tokens(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->sessionLogin('A', $user->email, self::PASSWORD)->assertOk();
        $this->sessionLogin('B', $user->email, self::PASSWORD)->assertOk();
        $this->browser('A', 'GET', '/api/user')->assertOk();
        $this->browser('B', 'GET', '/api/user')->assertOk();
        $pat = $user->createToken('legacy-client')->plainTextToken;

        $token = Password::broker()->createToken($user);
        $this->csrf('R');
        $this->reset('R', $this->resetPayload($token, $user->email))->assertOk();

        $this->browser('A', 'GET', '/api/user')->assertUnauthorized();
        $this->browser('B', 'GET', '/api/user')->assertUnauthorized();

        // Client par jeton seul : aucun reste de session en mémoire du processus de test.
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->withToken($pat)->getJson('/api/user')->assertUnauthorized(); // AUTH3-05: PAT revoked by the reset
        $this->assertSame(0, $user->tokens()->count());
    }
}
