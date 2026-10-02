<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/*
 * AUTH3-04 — vérification d'adresse email obligatoire, au-dessus de la session
 * Sanctum (AUTH3-01) et de la récupération de mot de passe (AUTH3-03).
 *
 * Même approche que PasswordRecoveryTest : vraie pile HTTP (CORS, Sanctum
 * stateful, cookies chiffrés, session, CSRF réellement vérifiée), avec des
 * « navigateurs » de test indépendants (un pot de cookies chacun).
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = 'http://localhost:5173';

    private const PASSWORD = 'secret-password-123';

    /**
     * Routes authentifiées accessibles sans email vérifié (orientation / fermeture
     * de session ; AUTH3-05 : sécurité du compte, changement de mot de passe).
     */
    private const ALLOWED_WHILE_UNVERIFIED = ['GET api/user', 'POST api/logout', 'PUT api/user/password'];

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

    // Lien ouvert depuis le client mail : simple navigation GET, sans Origin SPA.
    private function openLink(string $name, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->unencryptedCookies = [];
        $jar = $this->jars[$name] ?? [];

        $response = $this->withUnencryptedCookies($jar)->get($url, ['Accept' => 'text/html']);

        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
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

    private function login(string $name, string $email, string $password): TestResponse
    {
        $this->csrf($name);

        return $this->browser($name, 'POST', '/api/login', ['email' => $email, 'password' => $password]);
    }

    private function resend(string $name, string $email, bool $sendXsrf = true): TestResponse
    {
        return $this->browser($name, 'POST', '/api/email/verification-notification', ['email' => $email], $sendXsrf);
    }

    private function unverifiedUser(array $attributes = []): User
    {
        return User::factory()->unverified()->create(['password' => self::PASSWORD] + $attributes);
    }

    // URL du lien de vérification telle qu'envoyée dans l'email.
    private function verificationUrl(User $user): string
    {
        Notification::fake();
        $user->sendEmailVerificationNotification();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        return $url;
    }

    private function frontend(string $path): string
    {
        return config('app.frontend_url').$path;
    }

    // ---------------------------------------------------------------- A. register

    public function test_registration_sends_one_verification_email_without_logging_in(): void
    {
        Notification::fake();

        $this->csrf('a');
        $this->browser('a', 'POST', '/api/register', [
            'name' => 'New User', 'email' => 'new-user@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('email', 'new-user@example.test')->assertJsonMissingPath('token');

        $user = User::where('email', 'new-user@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        Notification::assertCount(1);

        // Pas de connexion ni de jeton.
        $this->browser('a', 'GET', '/api/user')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_verification_mail_failure_does_not_turn_a_created_account_into_an_error(): void
    {
        Exceptions::fake();
        // Transport SMTP injoignable : l'envoi échoue réellement.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->csrf('a');
        $response = $this->browser('a', 'POST', '/api/register', [
            'name' => 'Mail Down', 'email' => 'mail-down@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);

        // Contrat d'inscription inchangé : succès, aucun détail SMTP exposé.
        $response->assertOk()->assertJsonPath('email', 'mail-down@example.test');
        $this->assertStringNotContainsStringIgnoringCase('smtp', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('connection', $response->getContent());
        $this->assertDatabaseHas('users', ['email' => 'mail-down@example.test', 'email_verified_at' => null]);
        // Échec signalé côté serveur uniquement.
        Exceptions::assertReported(TransportException::class);

        // Le compte existe : on ne doit pas réessayer l'inscription mais redemander l'email.
        $this->browser('a', 'POST', '/api/register', [
            'name' => 'Mail Down', 'email' => 'mail-down@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertStatus(422);

        $user = User::where('email', 'mail-down@example.test')->firstOrFail();
        Notification::fake();
        $this->resend('a', 'mail-down@example.test')->assertOk()->assertExactJson(['message' => EmailVerificationController::RESEND_REQUESTED]);
        // PREAUTH-MAIL-01 : renvoi traité par la file database (vrai worker).
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->run();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    // ---------------------------------------------------------------- B-D. login

    public function test_correct_password_on_an_unverified_account_is_refused_without_a_session(): void
    {
        $user = $this->unverifiedUser();

        $this->login('b', $user->email, self::PASSWORD)
            ->assertStatus(403)
            ->assertExactJson(['message' => AuthController::EMAIL_UNVERIFIED_MESSAGE, 'code' => 'EMAIL_UNVERIFIED']);

        // Aucune session authentifiée, aucun jeton, rien d'autre de déverrouillé.
        $this->browser('b', 'GET', '/api/user')->assertUnauthorized();
        $this->browser('b', 'GET', '/api/expenses')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertNull($user->fresh()->last_activity);
    }

    public function test_wrong_password_on_an_unverified_account_keeps_the_invalid_credentials_contract(): void
    {
        $unverified = $this->unverifiedUser();
        $verified = User::factory()->create(['password' => self::PASSWORD]);

        $forUnverified = $this->login('c1', $unverified->email, 'wrong-password');
        $forVerified = $this->login('c2', $verified->email, 'wrong-password');
        $forUnknown = $this->login('c3', 'nobody@example.test', 'wrong-password');

        // Rien ne révèle l'état de vérification avant la preuve du mot de passe.
        foreach ([$forUnverified, $forVerified, $forUnknown] as $response) {
            $response->assertStatus(401)->assertExactJson(['message' => 'Invalid credentials']);
        }
    }

    public function test_a_verified_account_logs_in_normally_and_reaches_business_routes(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->login('d', $user->email, self::PASSWORD)->assertOk()->assertJsonPath('message', 'Logged in');

        $this->browser('d', 'GET', '/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->browser('d', 'GET', '/api/expenses')->assertOk();
        $this->browser('d', 'GET', '/api/budgets')->assertOk();
        $this->browser('d', 'GET', '/api/dashboard/categories')->assertOk();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    // ---------------------------------------------------------------- E-K. signed link

    public function test_a_valid_signed_link_verifies_the_email_without_logging_in(): void
    {
        Event::fake([Verified::class]);
        $user = $this->unverifiedUser();
        $url = $this->verificationUrl($user);

        // Lien vers l'API (APP_URL), signé, avec expiration, id et hash d'email.
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/api/email/verify/'.$user->id.'/'.sha1($user->email).'?', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);

        $this->openLink('e', $url)->assertRedirect($this->frontend('/login?email_verified=1'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatchedTimes(Verified::class, 1);

        // Pas de connexion automatique, pas de jeton.
        $this->browser('e', 'GET', '/api/user')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::count());

        // L'utilisateur peut maintenant se connecter.
        $this->login('e', $user->email, self::PASSWORD)->assertOk();
    }

    public function test_tampered_links_are_rejected(): void
    {
        $user = $this->unverifiedUser();
        $other = $this->unverifiedUser();
        $url = $this->verificationUrl($user);
        $invalid = $this->frontend('/verify-email?verification=invalid');

        $tampered = [
            'signature' => preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $url),
            'user id' => str_replace('/verify/'.$user->id.'/', '/verify/'.$other->id.'/', $url),
            'email hash' => str_replace(sha1($user->email), sha1($other->email), $url),
            'expiry' => preg_replace('/expires=(\d+)/', 'expires=9999999999', $url),
            'signature removed' => preg_replace('/&?signature=[0-9a-f]+/', '', $url),
        ];

        foreach ($tampered as $case => $link) {
            $this->assertNotSame($url, $link, $case);
            $this->openLink('f', $link)->assertRedirect($invalid);
        }

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_a_signed_link_for_a_previous_email_address_is_rejected(): void
    {
        $user = $this->unverifiedUser(['email' => 'before@example.test']);
        $url = $this->verificationUrl($user);

        // Signature intacte, mais l'adresse a changé depuis l'envoi : le hash ne correspond plus.
        $user->forceFill(['email' => 'after@example.test'])->save();

        $this->openLink('g', $url)->assertRedirect($this->frontend('/verify-email?verification=invalid'));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = $this->unverifiedUser();
        $url = $this->verificationUrl($user);

        $this->travel(61)->minutes();

        $this->openLink('i', $url)->assertRedirect($this->frontend('/verify-email?verification=invalid'));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_reusing_a_valid_link_is_an_idempotent_success(): void
    {
        Event::fake([Verified::class]);
        $user = $this->unverifiedUser();
        $url = $this->verificationUrl($user);

        $this->openLink('j', $url)->assertRedirect($this->frontend('/login?email_verified=1'));
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->travel(5)->minutes();
        $this->openLink('j', $url)->assertRedirect($this->frontend('/login?email_verified=1'));

        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_the_link_works_from_a_browser_with_another_users_session_without_switching_identity(): void
    {
        $owner = User::factory()->create(['password' => self::PASSWORD]);
        $target = $this->unverifiedUser();
        $url = $this->verificationUrl($target);

        $this->login('k', $owner->email, self::PASSWORD)->assertOk();
        $this->openLink('k', $url)->assertRedirect($this->frontend('/login?email_verified=1'));

        $this->assertNotNull($target->fresh()->email_verified_at);
        // La session existante reste celle de son propriétaire (pas de connexion du compte vérifié).
        $this->browser('k', 'GET', '/api/user')->assertOk()->assertJsonPath('id', $owner->id);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_verification_link_has_a_modest_dedicated_limiter(): void
    {
        $user = $this->unverifiedUser();
        $url = $this->verificationUrl($user);

        for ($i = 0; $i < 20; $i++) {
            $this->openLink('l', $url)->assertRedirect($this->frontend('/login?email_verified=1'));
        }

        $this->openLink('l', $url)->assertRedirect($this->frontend('/verify-email?verification=rate_limited'));
    }

    // ---------------------------------------------------------------- L-Q. resend

    public function test_resend_response_is_identical_for_unverified_unknown_and_verified_emails(): void
    {
        Notification::fake();
        $unverified = $this->unverifiedUser();
        $verified = User::factory()->create();

        $this->csrf('m');
        $responses = [
            'unverified' => $this->resend('m', $unverified->email),
            'unknown' => $this->resend('m', 'nobody@example.test'),
            'verified' => $this->resend('m', $verified->email),
        ];

        foreach ($responses as $case => $response) {
            $response->assertOk()->assertExactJson(['message' => EmailVerificationController::RESEND_REQUESTED]);
            $this->assertSame($responses['unknown']->getContent(), $response->getContent(), $case);
        }

        // PREAUTH-MAIL-01 : envoi par une tâche en file (connexion database), vraie
        // exécution par un worker avant les assertions.
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->run();

        // Seul le compte existant non vérifié reçoit un email.
        Notification::assertSentToTimes($unverified, VerifyEmail::class, 1);
        Notification::assertNotSentTo($verified, VerifyEmail::class);
        Notification::assertCount(1);
    }

    public function test_resend_validates_the_email_format_only(): void
    {
        Notification::fake();
        $this->csrf('o');

        foreach ([['email' => 'not-an-email'], ['email' => ['x@example.test']], []] as $payload) {
            $this->browser('o', 'POST', '/api/email/verification-notification', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('email');
        }

        Notification::assertNothingSent();
    }

    public function test_resend_requires_the_csrf_token_for_the_spa(): void
    {
        Notification::fake();
        $user = $this->unverifiedUser();

        $this->csrf('p');
        $this->resend('p', $user->email, false)->assertStatus(419);

        Notification::assertNothingSent();
    }

    public function test_resend_limiter_does_not_reveal_account_existence(): void
    {
        Notification::fake();
        $user = $this->unverifiedUser();

        foreach ([$user->email, 'nobody@example.test'] as $email) {
            $this->csrf('q-'.$email);
            for ($i = 0; $i < 3; $i++) {
                $this->resend('q-'.$email, $email)->assertOk();
            }
            $this->resend('q-'.$email, $email)->assertStatus(429)->assertJsonPath('message', 'Too Many Attempts.');
        }
    }

    // ---------------------------------------------------------------- R-T. sessions / routes

    public function test_a_legacy_unverified_session_keeps_identity_and_logout_but_no_business_access(): void
    {
        // Session ouverte avant AUTH3-04 : simulée par un compte vérifié, connecté,
        // dont l'adresse redevient non vérifiée.
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->login('r', $user->email, self::PASSWORD)->assertOk();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->browser('r', 'GET', '/api/user')->assertOk()->assertJsonPath('email_verified_at', null);

        $denied = ['email_unverified' => ['message' => EnsureEmailIsVerified::MESSAGE, 'code' => 'EMAIL_UNVERIFIED']];
        foreach ([['GET', '/api/expenses'], ['GET', '/api/budgets'], ['GET', '/api/dashboard/categories'], ['GET', '/api/export/pdf'], ['POST', '/api/expenses']] as [$method, $uri]) {
            $this->browser('r', $method, $uri)->assertStatus(403)->assertExactJson($denied['email_unverified']);
        }

        $this->browser('r', 'POST', '/api/logout')->assertOk();
        $this->browser('r', 'GET', '/api/user')->assertUnauthorized();
    }

    public function test_every_authenticated_business_route_requires_a_verified_email(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$route->uri();
                if (in_array($key, self::ALLOWED_WHILE_UNVERIFIED, true)) {
                    $this->assertNotContains('verified', $middleware, $key);
                } else {
                    $this->assertContains('verified', $middleware, $key);
                    $checked++;
                }
            }
        }

        // Dépenses (6 + export), budgets (5 + résumé + export), tableau de bord (3).
        $this->assertGreaterThanOrEqual(17, $checked);
    }

    public function test_public_verification_routes_are_not_behind_auth(): void
    {
        $routes = $this->app['router']->getRoutes();
        $middlewareFor = fn (string $method, string $uri) => $routes->match(Request::create($uri, $method))->gatherMiddleware();

        $resend = $middlewareFor('POST', '/api/email/verification-notification');
        $this->assertContains('throttle:verification-resend', $resend);
        $this->assertNotContains('auth:sanctum', $resend);

        $link = $middlewareFor('GET', '/api/email/verify/1/abc');
        $this->assertContains('throttle:verification-link', $link);
        $this->assertNotContains('auth:sanctum', $link);
    }

    // ---------------------------------------------------------------- U. password reset

    public function test_password_reset_does_not_verify_the_email(): void
    {
        $user = $this->unverifiedUser();
        $token = Password::broker()->createToken($user);

        $this->csrf('u');
        $this->browser('u', 'POST', '/api/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('brand-new-password-456', $fresh->password));
        $this->assertNull($fresh->email_verified_at);

        // Nouveau mot de passe correct, mais la vérification reste exigée.
        $this->login('u', $user->email, 'brand-new-password-456')
            ->assertStatus(403)
            ->assertJsonPath('code', 'EMAIL_UNVERIFIED');
    }
}
