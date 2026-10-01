<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordController;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/*
 * AUTH3-05 — sécurité du mot de passe et des sessions :
 *  - changement de mot de passe authentifié (preuve du mot de passe actuel) ;
 *  - la session courante survit, les autres sessions du compte sont fermées ;
 *  - jeton « remember » renouvelé, jetons personnels du compte révoqués ;
 *  - la réinitialisation (AUTH3-03) révoque aussi les jetons personnels.
 *
 * Même approche que SessionAuthenticationTest : vraie pile HTTP (CORS, Sanctum
 * stateful, cookies chiffrés, session, CSRF réellement vérifiée), avec des
 * « navigateurs » de test indépendants (un pot de cookies chacun).
 */
class PasswordSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = 'http://localhost:5173';

    private const PASSWORD = 'secret-password-123';

    private const NEW_PASSWORD = 'brand-new-password-456';

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

    // Isolement de chaque requête simulée, comme en production (un processus
    // par requête) : dans un test, le magasin de session en mémoire est partagé
    // et Store::loadSession() fusionne avec les attributs de la requête
    // précédente ; sans ce vidage, une requête sans session hériterait de la
    // connexion d'un autre « navigateur ».
    private function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->unencryptedCookies = [];
    }

    // Identifiant de session réel (cookie déchiffré), indépendant du chiffrement.
    private function sessionId(string $name): string
    {
        return CookieValuePrefix::remove($this->app['encrypter']->decrypt($this->jars[$name]['laravel_session'], false));
    }

    private function browser(string $name, string $method, string $uri, array $data = [], bool $sendXsrf = true): TestResponse
    {
        $this->freshRequestState();
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

    private function login(string $name, string $email, string $password): TestResponse
    {
        $this->browser($name, 'GET', '/sanctum/csrf-cookie')->assertNoContent();

        return $this->browser($name, 'POST', '/api/login', ['email' => $email, 'password' => $password]);
    }

    private function changePassword(string $name, array $payload, bool $sendXsrf = true): TestResponse
    {
        return $this->browser($name, 'PUT', '/api/user/password', $payload, $sendXsrf);
    }

    private function payload(string $current = self::PASSWORD, string $new = self::NEW_PASSWORD, ?string $confirmation = null): array
    {
        return ['current_password' => $current, 'password' => $new, 'password_confirmation' => $confirmation ?? $new];
    }

    // Client par jeton personnel (non navigateur) : aucun cookie, en-tête Bearer seul.
    private function withPat(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->freshRequestState();
        $response = $this->withToken($token)->json($method, $uri, $data);
        $this->withoutToken();

        return $response;
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::PASSWORD] + $attributes);
    }

    // ---------------------------------------------------------------- A, H-K. change + sessions

    public function test_a_password_change_keeps_the_current_session_and_closes_the_other_ones(): void
    {
        $user = $this->user();
        $this->login('A', $user->email, self::PASSWORD)->assertOk();
        $this->login('B', $user->email, self::PASSWORD)->assertOk();
        $this->browser('B', 'GET', '/api/user')->assertOk();
        $oldSessionCookie = $this->jars['A']['laravel_session'];
        $oldSessionId = $this->sessionId('A');

        $this->changePassword('A', $this->payload())
            ->assertOk()
            ->assertExactJson(['message' => PasswordController::UPDATED]);

        // A : toujours connecté (nouvel identifiant de session), accès métier intact.
        $this->assertNotSame($oldSessionId, $this->sessionId('A'));
        $this->browser('A', 'GET', '/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->browser('A', 'GET', '/api/expenses')->assertOk();
        $this->browser('A', 'GET', '/api/user')->assertOk();

        // B : fermée par le serveur à sa requête suivante.
        $this->browser('B', 'GET', '/api/user')->assertUnauthorized();
        $this->browser('B', 'GET', '/api/expenses')->assertUnauthorized();

        // Une copie de l'ancien cookie de A ne donne plus accès.
        $this->jars['stolen'] = ['laravel_session' => $oldSessionCookie] + $this->jars['A'];
        $this->browser('stolen', 'GET', '/api/user')->assertUnauthorized();

        // Ancien mot de passe refusé, nouveau accepté ; aucun jeton émis.
        $this->login('C', $user->email, self::PASSWORD)->assertUnauthorized();
        $this->login('D', $user->email, self::NEW_PASSWORD)->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(0, PersonalAccessToken::count());

        // A peut toujours se déconnecter normalement (AUTH3-01).
        $this->browser('A', 'POST', '/api/logout')->assertOk();
        $this->browser('A', 'GET', '/api/user')->assertUnauthorized();
    }

    public function test_a_password_change_rotates_the_remember_token_and_kills_old_recallers(): void
    {
        $user = $this->user();
        $guard = $this->app['auth']->guard('web');
        $recaller = fn (User $u) => [$guard->getRecallerName() => $u->id.'|'.$u->getRememberToken().'|'.$guard->hashPasswordForCookie($u->getAuthPassword())];
        $oldRecaller = $recaller($user);
        $oldRememberToken = $user->getRememberToken();

        // Navigateur sans session, avec le seul cookie « remember » (chiffré comme en vrai).
        $withRecaller = function (array $cookie): TestResponse {
            $this->freshRequestState();
            $response = $this->withCredentials()->withCookies($cookie)->getJson('/api/user', ['Origin' => self::SPA, 'Referer' => self::SPA.'/']);
            $this->defaultCookies = [];

            return $response;
        };

        // Contrôle : le « remember » courant ouvre bien une session.
        $withRecaller($oldRecaller)->assertOk();

        $this->login('A', $user->email, self::PASSWORD)->assertOk();
        $this->changePassword('A', $this->payload())->assertOk();

        $this->assertNotSame($oldRememberToken, $user->fresh()->getRememberToken());
        $withRecaller($oldRecaller)->assertUnauthorized();
    }

    // ---------------------------------------------------------------- M-N. PAT revocation on change

    public function test_a_password_change_revokes_only_the_owners_personal_access_tokens(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $pat1 = $owner->createToken('legacy-1')->plainTextToken;
        $pat2 = $owner->createToken('legacy-2')->plainTextToken;
        $pat3 = $other->createToken('legacy-3')->plainTextToken;
        $this->withPat($pat1, 'GET', '/api/user')->assertOk();

        $this->login('A', $owner->email, self::PASSWORD)->assertOk();
        $this->changePassword('A', $this->payload())->assertOk()->assertJsonMissingPath('token');

        $this->assertSame(0, $owner->tokens()->count());
        $this->withPat($pat1, 'GET', '/api/user')->assertUnauthorized();
        $this->withPat($pat2, 'GET', '/api/expenses')->assertUnauthorized();

        $this->assertSame(1, $other->tokens()->count());
        $this->withPat($pat3, 'GET', '/api/user')->assertOk()->assertJsonPath('id', $other->id);

        $this->browser('A', 'GET', '/api/user')->assertOk();
    }

    public function test_a_personal_access_token_cannot_change_the_password(): void
    {
        $user = $this->user();
        $pat = $user->createToken('legacy')->plainTextToken;

        $this->withPat($pat, 'PUT', '/api/user/password', $this->payload())
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Session authentication required']);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    // ---------------------------------------------------------------- B. unverified legacy session

    public function test_a_legacy_unverified_session_can_still_secure_its_account(): void
    {
        $user = $this->user();
        $this->login('A', $user->email, self::PASSWORD)->assertOk();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->changePassword('A', $this->payload())->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertNull($user->fresh()->email_verified_at);
        $this->browser('A', 'GET', '/api/user')->assertOk();
        // Le changement de mot de passe ne lève pas l'exigence de vérification.
        $this->browser('A', 'GET', '/api/expenses')->assertStatus(403)->assertJsonPath('code', 'EMAIL_UNVERIFIED');
    }

    // ---------------------------------------------------------------- C-G. refusals without side effects

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $user = $this->user();
        $pat = $user->createToken('legacy')->plainTextToken;
        $rememberToken = $user->getRememberToken();
        $this->login('A', $user->email, self::PASSWORD)->assertOk();
        $this->login('B', $user->email, self::PASSWORD)->assertOk();
        $sessionId = $this->sessionId('A');

        $this->changePassword('A', $this->payload('not-my-password'))
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', PasswordController::CURRENT_INVALID)
            ->assertJsonMissingValidationErrors(['password']);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->password));
        $this->assertSame($rememberToken, $fresh->getRememberToken());
        $this->assertSame(1, $user->tokens()->count());
        $this->withPat($pat, 'GET', '/api/user')->assertOk();
        $this->assertSame($sessionId, $this->sessionId('A'));
        $this->browser('A', 'GET', '/api/user')->assertOk();
        $this->browser('B', 'GET', '/api/user')->assertOk();
    }

    public function test_invalid_new_passwords_are_validation_errors_without_side_effects(): void
    {
        $user = $this->user();
        $this->login('A', $user->email, self::PASSWORD)->assertOk();
        $this->login('B', $user->email, self::PASSWORD)->assertOk();

        $this->changePassword('A', $this->payload(self::PASSWORD, self::NEW_PASSWORD, 'something-else-789'))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->changePassword('A', $this->payload(self::PASSWORD, 'short'))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->changePassword('A', ['current_password' => self::PASSWORD, 'password' => ['x'], 'password_confirmation' => ['x']])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->changePassword('A', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->browser('B', 'GET', '/api/user')->assertOk();
    }

    public function test_an_unauthenticated_password_change_is_refused(): void
    {
        $this->browser('X', 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->changePassword('X', $this->payload())->assertUnauthorized();
    }

    public function test_a_password_change_requires_the_csrf_token(): void
    {
        $user = $this->user();
        $this->login('A', $user->email, self::PASSWORD)->assertOk();

        $this->changePassword('A', $this->payload(), false)->assertStatus(419);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->browser('A', 'GET', '/api/user')->assertOk();
    }

    public function test_password_change_attempts_are_rate_limited_per_account(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->login('A', $user->email, self::PASSWORD)->assertOk();
        $this->login('O', $other->email, self::PASSWORD)->assertOk();

        // Essais sur le mot de passe actuel (sans changer d'identifiants entre deux essais).
        for ($i = 0; $i < 5; $i++) {
            $this->changePassword('A', $this->payload('wrong-guess-'.$i))->assertStatus(422);
        }
        $this->changePassword('A', $this->payload())->assertStatus(429);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));

        // Autre compte, même IP : non limité.
        $this->changePassword('O', $this->payload())->assertOk();
    }

    public function test_the_password_route_is_account_security_not_business(): void
    {
        $route = $this->app['router']->getRoutes()->match(Request::create('/api/user/password', 'PUT'));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('activity.check', $middleware);
        $this->assertContains('throttle:password-change', $middleware);
        $this->assertNotContains('verified', $middleware);
    }

    // ---------------------------------------------------------------- O-S. password reset (AUTH3-03)

    public function test_a_password_reset_closes_every_session_and_revokes_only_the_owners_tokens(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->login('A', $user->email, self::PASSWORD)->assertOk();
        $this->login('B', $user->email, self::PASSWORD)->assertOk();
        $pat = $user->createToken('legacy')->plainTextToken;
        $otherPat = $other->createToken('legacy')->plainTextToken;
        $rememberToken = $user->getRememberToken();

        // Réinitialisation depuis un troisième navigateur, sans session du compte.
        $token = Password::broker()->createToken($user);
        $this->browser('R', 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('R', 'POST', '/api/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->browser('A', 'GET', '/api/user')->assertUnauthorized();
        $this->browser('B', 'GET', '/api/user')->assertUnauthorized();
        $this->browser('R', 'GET', '/api/user')->assertUnauthorized(); // pas de connexion automatique

        $this->withPat($pat, 'GET', '/api/user')->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
        $this->withPat($otherPat, 'GET', '/api/user')->assertOk();

        $fresh = $user->fresh();
        $this->assertNotSame($rememberToken, $fresh->getRememberToken());
        $this->assertNotNull($fresh->email_verified_at);
        $this->login('C', $user->email, self::PASSWORD)->assertUnauthorized();
        $this->login('D', $user->email, self::NEW_PASSWORD)->assertOk();
    }

    public function test_a_password_reset_of_an_unverified_account_revokes_tokens_without_verifying_it(): void
    {
        $user = User::factory()->unverified()->create(['password' => self::PASSWORD]);
        $pat = $user->createToken('legacy')->plainTextToken;

        $token = Password::broker()->createToken($user);
        $this->browser('R', 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('R', 'POST', '/api/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->withPat($pat, 'GET', '/api/user')->assertUnauthorized();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->login('C', $user->email, self::NEW_PASSWORD)->assertStatus(403)->assertJsonPath('code', 'EMAIL_UNVERIFIED');
    }

    public function test_a_failed_reset_revokes_nothing(): void
    {
        $user = $this->user();
        $pat = $user->createToken('legacy')->plainTextToken;
        $this->login('A', $user->email, self::PASSWORD)->assertOk();

        $this->browser('R', 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('R', 'POST', '/api/reset-password', [
            'token' => 'not-a-valid-token', 'email' => $user->email,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->withPat($pat, 'GET', '/api/user')->assertOk();
        $this->browser('A', 'GET', '/api/user')->assertOk();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }
}
