<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/*
 * AUTH3-01 — authentification navigateur par session (Sanctum SPA).
 *
 * Chaque requête passe par la vraie pile HTTP (CORS, Sanctum stateful,
 * chiffrement des cookies, session, CSRF). Le client de test se comporte
 * comme le navigateur : il conserve les cookies reçus (valeurs chiffrées) et
 * renvoie XSRF-TOKEN dans l'en-tête X-XSRF-TOKEN, comme Axios.
 *
 * La vérification CSRF du framework se désactive d'elle-même en tests
 * unitaires : la classe MONEVA est remplacée ici par une sous-classe qui ne
 * désactive que ce contournement, pour tester la vraie protection.
 */
class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = 'http://localhost:5173';

    private const PASSWORD = 'secret-password-123';

    /** @var array<string, string> cookies as the browser holds them (encrypted values) */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic trusted origin, whatever FRONTEND_URL the local .env holds.
        config(['cors.allowed_origins' => [self::SPA]]);

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- browser helpers

    private function browser(string $method, string $uri, array $data = [], bool $sendXsrf = true, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->unencryptedCookies = [];

        $defaults = ['Origin' => self::SPA, 'Referer' => self::SPA.'/', 'Accept' => 'application/json'];
        if ($sendXsrf && isset($this->jar['XSRF-TOKEN'])) {
            $defaults['X-XSRF-TOKEN'] = $this->jar['XSRF-TOKEN'];
        }

        $response = $this->withCredentials()
            ->withUnencryptedCookies($this->jar)
            ->json($method, $uri, $data, array_merge($defaults, $headers));

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    private function decryptedCookie(string $name): ?string
    {
        $raw = $this->jar[$name] ?? null;

        return $raw === null ? null : CookieValuePrefix::remove($this->app['encrypter']->decrypt($raw, false));
    }

    private function sessionId(): ?string
    {
        return $this->decryptedCookie(config('session.cookie'));
    }

    private function cookieFrom(TestResponse $response, string $name): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    private function login(User $user): TestResponse
    {
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        return $this->browser('POST', '/api/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    // ---------------------------------------------------------------- tests

    public function test_csrf_cookie_bootstrap_sets_a_readable_xsrf_cookie_and_an_httponly_session_cookie(): void
    {
        $response = $this->browser('GET', '/sanctum/csrf-cookie');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::SPA)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $xsrf = $this->cookieFrom($response, 'XSRF-TOKEN');
        $session = $this->cookieFrom($response, config('session.cookie'));

        $this->assertNotNull($xsrf);
        $this->assertFalse($xsrf->isHttpOnly(), 'XSRF-TOKEN must stay readable by the SPA');
        $this->assertNotNull($session);
        $this->assertTrue($session->isHttpOnly(), 'the session cookie must never be readable by JavaScript');
        $this->assertSame('lax', $session->getSameSite());
        $this->assertFalse($session->isSecure(), 'local HTTP development');
        $this->assertSame(40, strlen((string) $this->sessionId()));
        $this->assertSame(40, strlen((string) $this->decryptedCookie('XSRF-TOKEN')));
    }

    public function test_production_configuration_can_enforce_secure_cookies(): void
    {
        config(['session.secure' => true]);

        $response = $this->browser('GET', '/sanctum/csrf-cookie');

        $this->assertTrue($this->cookieFrom($response, config('session.cookie'))->isSecure());
        $this->assertTrue($this->cookieFrom($response, 'XSRF-TOKEN')->isSecure());
    }

    /**
     * Freezes the clock at the real current time: cookie expiry is checked
     * against the real clock by Symfony, so a frozen date in the past would
     * make every issued cookie look already expired to this test browser.
     */
    private function freezeNow(): Carbon
    {
        $now = Carbon::now()->startOfSecond();
        Carbon::setTestNow($now);

        return $now;
    }

    public function test_spa_login_regenerates_the_session_and_issues_no_personal_access_token(): void
    {
        $now = $this->freezeNow();
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $sessionBefore = $this->sessionId();
        $tokensBefore = PersonalAccessToken::count();

        $response = $this->browser('POST', '/api/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertOk()
            ->assertJsonPath('message', 'Logged in')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user.password');

        $sessionAfter = $this->sessionId();
        $this->assertNotNull($sessionBefore);
        $this->assertNotNull($sessionAfter);
        $this->assertNotSame($sessionBefore, $sessionAfter, 'session fixation: the session id must change at login');

        $this->assertSame($tokensBefore, PersonalAccessToken::count(), 'browser login must not create a PAT');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame($now->format('Y-m-d H:i:s'), $user->fresh()->getRawOriginal('last_activity'));

        // The session cookie alone authenticates: no Authorization header is ever sent.
        $this->browser('GET', '/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonMissingPath('password');
    }

    public function test_spa_login_without_or_with_a_forged_csrf_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        $credentials = ['email' => $user->email, 'password' => self::PASSWORD];

        $this->browser('POST', '/api/login', $credentials, false)->assertStatus(419);
        $this->browser('POST', '/api/login', $credentials, false, ['X-XSRF-TOKEN' => 'forged-token'])->assertStatus(419);

        $this->browser('GET', '/api/user')->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_stateful_api_writes_require_the_csrf_token(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user)->assertOk();

        $expense = ['description' => 'Coiffure', 'amount' => 15000, 'category' => 'Beauté & soins', 'date' => now()->toDateString()];

        $this->browser('POST', '/api/expenses', $expense, false)->assertStatus(419);
        $this->assertDatabaseCount('expenses', 0);

        $this->browser('POST', '/api/expenses', $expense)->assertCreated();
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_user_endpoint_without_a_session_is_unauthorized(): void
    {
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_session_and_regenerates_the_csrf_token(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user)->assertOk();
        $this->browser('GET', '/api/user')->assertOk();

        $oldJar = $this->jar;
        $oldSession = $this->sessionId();
        $oldXsrf = $this->decryptedCookie('XSRF-TOKEN');

        $this->browser('POST', '/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->assertNotSame($oldSession, $this->sessionId(), 'logout must drop the authenticated session id');
        $this->assertNotSame($oldXsrf, $this->decryptedCookie('XSRF-TOKEN'), 'logout must regenerate the CSRF token');

        $this->browser('GET', '/api/user')->assertUnauthorized();

        // Replaying the pre-logout cookies (stolen / stale tab): the old CSRF
        // token is dead, and the old session no longer authenticates.
        $this->jar = $oldJar;
        $this->browser('POST', '/api/logout')->assertStatus(419);
        $this->jar = $oldJar;
        $this->browser('GET', '/api/user')->assertUnauthorized();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_registration_goes_through_csrf_and_does_not_log_the_user_in(): void
    {
        $payload = ['name' => 'Session User', 'email' => 'session@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];

        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('POST', '/api/register', $payload, false)->assertStatus(419);
        $this->assertDatabaseMissing('users', ['email' => 'session@example.test']);

        $this->browser('POST', '/api/register', $payload)
            ->assertOk()
            ->assertJsonPath('email', 'session@example.test')
            ->assertJsonMissingPath('token');

        // Current contract preserved: registering does not open a session.
        $this->browser('GET', '/api/user')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_session_login_keeps_the_dedicated_rate_limiter(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        for ($i = 0; $i < 5; $i++) {
            $this->browser('POST', '/api/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnauthorized();
        }

        $this->browser('POST', '/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(429);
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_inactivity_expiry_closes_the_browser_session(): void
    {
        $this->freezeNow();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user)->assertOk();

        $user->forceFill(['last_activity' => now()->subMinutes(1440)])->save();
        $this->browser('GET', '/api/user')->assertOk();

        $user->forceFill(['last_activity' => now()->subMinutes(1441)])->save();
        $response = $this->browser('GET', '/api/user');
        $response->assertUnauthorized();
        $this->assertStringStartsWith('Session expir', (string) $response->json('message'));

        // The session itself was closed: fresh activity does not revive it.
        $user->forceFill(['last_activity' => now()])->save();
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_cors_grants_credentials_only_to_the_trusted_spa_origin(): void
    {
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token,accept',
        ]);

        $preflight(self::SPA)
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::SPA)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $evil = $preflight('https://evil.example');
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));

        // An untrusted origin is not "stateful": it never gets a session login.
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertForbidden();
    }

    /**
     * A3-F1 — an exhausted generic "api" request budget must never keep a
     * session alive: logout is not subject to that limiter, everything else is.
     */
    public function test_logout_still_closes_the_session_when_the_generic_api_limiter_is_exhausted(): void
    {
        $now = $this->freezeNow();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user)->assertOk();

        for ($i = 0; $i < 60; $i++) {
            $this->browser('GET', '/api/expenses')->assertOk();
        }
        $this->browser('GET', '/api/expenses')->assertStatus(429); // generic limiter still enforced
        $sessionBeforeLogout = $this->jar;

        $this->browser('POST', '/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->browser('GET', '/api/user')->assertUnauthorized();

        // Once the throttle window has elapsed, the pre-logout session is really gone.
        Carbon::setTestNow($now->copy()->addSeconds(61));
        $user->forceFill(['last_activity' => Carbon::now()])->save();
        $this->jar = $sessionBeforeLogout;
        $this->browser('GET', '/api/user')->assertUnauthorized();
    }

    public function test_logout_keeps_auth_and_csrf_and_only_drops_the_generic_limiter(): void
    {
        $router = $this->app['router'];
        $stackFor = fn (string $method, string $uri) => $router->gatherRouteMiddleware(
            $router->getRoutes()->match(\Illuminate\Http\Request::create($uri, $method))
        );

        $logout = $stackFor('POST', '/api/logout');
        $this->assertContains(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, $logout); // session + CSRF
        $this->assertContains('App\Http\Middleware\Authenticate:sanctum', $logout);
        $this->assertNotContains('Illuminate\Routing\Middleware\ThrottleRequests:api', $logout);

        foreach ([['GET', '/api/user'], ['GET', '/api/expenses'], ['POST', '/api/budgets']] as [$method, $uri]) {
            $this->assertContains('Illuminate\Routing\Middleware\ThrottleRequests:api', $stackFor($method, $uri), "$method $uri");
        }
        $this->assertSame([], $this->app->make(PreventRequestForgery::class)->getExcludedPaths());

        // Still requires authentication...
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('POST', '/api/logout')->assertUnauthorized();

        // ...and a valid CSRF token for a stateful request: a forged logout changes nothing.
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login($user)->assertOk();
        $this->browser('POST', '/api/logout', [], false)->assertStatus(419);
        $this->browser('GET', '/api/user')->assertOk();
    }

    public function test_bearer_clients_outside_the_browser_flow_keep_working_without_csrf(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $user->createToken('api-client')->plainTextToken;
        $expense = ['description' => 'Taxi', 'amount' => 2000, 'category' => 'Transport', 'date' => now()->toDateString()];

        // Non-stateful (no first-party origin): Sanctum does not apply CSRF.
        $this->withToken($token)->postJson('/api/expenses', $expense)->assertCreated();

        // A request claiming the SPA origin is stateful and therefore CSRF-protected.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/expenses', $expense, ['Origin' => self::SPA])->assertStatus(419);

        $this->assertDatabaseCount('expenses', 1);
    }
}
