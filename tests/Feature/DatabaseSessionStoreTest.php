<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * A3-F3 — sessions persistées en base (driver « database », table « sessions »)
 * au lieu de fichiers éphémères sur Render.
 *
 * Les autres tests d'authentification utilisent le driver « array » ; ici le
 * driver « database » est forcé et le magasin de session est reconstruit avant
 * chaque requête simulée : une requête ne voit donc que ce que la table
 * contient, comme un nouveau processus en production.
 *
 * Adresse IP : seule la compatibilité de stockage locale est vérifiée ; l'IP
 * réelle derrière le proxy Render dépend de A3ARCH-F1 (TrustProxies).
 */
class DatabaseSessionStoreTest extends TestCase
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

        config(['session.driver' => 'database', 'cors.allowed_origins' => [self::SPA]]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

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

    // Chaque requête simulée repart d'un magasin de session neuf (aucun état en
    // mémoire hérité de la requête précédente) : la session vient de la table.
    // « auth.driver » (contrat Guard, d'où DatabaseSessionHandler tire user_id)
    // est un singleton : en production il est résolu à chaque requête.
    private function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->unencryptedCookies = [];
    }

    private function browser(string $name, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->freshRequestState();
        $jar = $this->jars[$name] ?? [];

        $defaults = ['Origin' => self::SPA, 'Referer' => self::SPA.'/', 'Accept' => 'application/json'];
        if (isset($jar['XSRF-TOKEN'])) {
            $defaults['X-XSRF-TOKEN'] = $jar['XSRF-TOKEN'];
        }

        $response = $this->withCredentials()->withUnencryptedCookies($jar)->json($method, $uri, $data, array_merge($defaults, $headers));

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

    private function login(string $name, User $user, array $headers = []): TestResponse
    {
        $this->browser($name, 'GET', '/sanctum/csrf-cookie', [], $headers)->assertNoContent();

        return $this->browser($name, 'POST', '/api/login', ['email' => $user->email, 'password' => self::PASSWORD], $headers);
    }

    // Identifiant de session réel (cookie déchiffré).
    private function sessionId(string $name): string
    {
        return CookieValuePrefix::remove($this->app['encrypter']->decrypt($this->jars[$name][config('session.cookie')], false));
    }

    private function row(string $sessionId): ?object
    {
        return DB::table('sessions')->where('id', $sessionId)->first();
    }

    private function user(): User
    {
        return User::factory()->create(['password' => self::PASSWORD]);
    }

    // ---------------------------------------------------------------- schema / wiring

    public function test_the_sessions_migration_creates_the_standard_laravel_table(): void
    {
        $this->assertTrue(Schema::hasTable('sessions'));
        $this->assertTrue(Schema::hasColumns('sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']));

        $indexes = collect(Schema::getIndexes('sessions'));
        $this->assertTrue($indexes->contains(fn ($i) => $i['primary'] && $i['columns'] === ['id']), 'primary key on id');
        $this->assertTrue($indexes->contains(fn ($i) => ! $i['primary'] && $i['columns'] === ['user_id']), 'index on user_id');
        $this->assertTrue($indexes->contains(fn ($i) => ! $i['primary'] && $i['columns'] === ['last_activity']), 'index on last_activity');

        // Rollback : le down() ne fait que supprimer la table (SQL simulé, rien n'est exécuté).
        $files = glob(database_path('migrations/*_create_sessions_table.php'));
        $this->assertCount(1, $files);
        $migration = require $files[0];
        $queries = DB::pretend(fn () => $migration->down());
        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/^drop table if exists [`"]?sessions[`"]?$/i', $queries[0]['query']);
    }

    public function test_the_database_driver_uses_the_default_connection_and_the_sessions_table(): void
    {
        $this->assertNull(config('session.connection'), 'no dedicated session connection');
        $this->assertSame('sessions', config('session.table'));

        $handler = $this->app['session']->driver()->getHandler();
        $this->assertInstanceOf(\Illuminate\Session\DatabaseSessionHandler::class, $handler);

        $connection = (fn () => $this->connection)->call($handler);
        $this->assertSame(config('database.default'), $connection->getName());
    }

    // ---------------------------------------------------------------- persistence

    public function test_login_persists_the_authenticated_session_in_the_database(): void
    {
        $user = $this->user();

        $this->login('a', $user, ['User-Agent' => 'MonevaTestAgent/1.0'])->assertOk();

        $row = $this->row($this->sessionId('a'));
        $this->assertNotNull($row, 'session row written to the sessions table');
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame('MonevaTestAgent/1.0', $row->user_agent);
        $this->assertNotEmpty($row->ip_address, 'local storage compatibility only (production IP: A3ARCH-F1)');
        $this->assertNotEmpty($row->payload);
        $this->assertEqualsWithDelta(Carbon::now()->getTimestamp(), (int) $row->last_activity, 5);
    }

    public function test_an_authenticated_follow_up_request_is_served_from_the_database_row(): void
    {
        $user = $this->user();
        $this->login('a', $user)->assertOk();

        $this->browser('a', 'GET', '/api/user')->assertOk()->assertJsonPath('id', $user->id);

        // Sans la ligne en base, le même cookie ne donne plus accès à rien.
        DB::table('sessions')->where('id', $this->sessionId('a'))->delete();
        $this->browser('a', 'GET', '/api/user')->assertUnauthorized();
    }

    public function test_last_activity_is_updated_on_later_requests(): void
    {
        $this->login('a', $this->user())->assertOk();
        $before = (int) $this->row($this->sessionId('a'))->last_activity;

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->browser('a', 'GET', '/api/user')->assertOk();

        $this->assertGreaterThan($before, (int) $this->row($this->sessionId('a'))->last_activity);
    }

    // ---------------------------------------------------------------- fixation / logout

    public function test_login_regenerates_the_session_id_and_the_pre_login_id_stays_anonymous(): void
    {
        $user = $this->user();

        $this->browser('a', 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $guestId = $this->sessionId('a');
        $guestJar = $this->jars['a'];

        $this->browser('a', 'POST', '/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $authenticatedId = $this->sessionId('a');

        $this->assertNotSame($guestId, $authenticatedId);
        $this->assertSame($user->id, (int) $this->row($authenticatedId)->user_id);
        $this->assertNull($this->row($guestId)?->user_id, 'the pre-login id is never bound to the user');

        // Un attaquant qui aurait fixé l'identifiant d'avant connexion n'obtient rien.
        $this->jars['attacker'] = $guestJar;
        $this->browser('attacker', 'GET', '/api/user')->assertUnauthorized();
    }

    public function test_logout_destroys_the_database_session_and_the_old_cookie_is_useless(): void
    {
        $this->login('a', $this->user())->assertOk();
        $oldId = $this->sessionId('a');
        $oldJar = $this->jars['a'];

        $this->browser('a', 'POST', '/api/logout')->assertOk();

        $this->assertNull($this->row($oldId), 'invalidate() removes the old row');
        $this->assertNotSame($oldId, $this->sessionId('a'));
        $this->browser('a', 'GET', '/api/user')->assertUnauthorized();

        $this->jars['replay'] = $oldJar;
        $this->browser('replay', 'GET', '/api/user')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- AUTH3-05 with stored sessions

    public function test_a_password_change_keeps_the_current_session_and_closes_the_other_one(): void
    {
        $user = $this->user();
        $this->login('a', $user)->assertOk();
        $this->login('b', $user)->assertOk();
        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->browser('a', 'PUT', '/api/user/password', [
            'current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->browser('a', 'GET', '/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->browser('b', 'GET', '/api/user')->assertUnauthorized();
    }

    public function test_a_password_reset_closes_every_stored_session(): void
    {
        $user = $this->user();
        $this->login('a', $user)->assertOk();
        $this->login('b', $user)->assertOk();

        $token = Password::broker()->createToken($user);
        $this->browser('r', 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('r', 'POST', '/api/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->browser('a', 'GET', '/api/user')->assertUnauthorized();
        $this->browser('b', 'GET', '/api/user')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- expiry

    public function test_a_stored_session_older_than_the_lifetime_is_not_authenticated(): void
    {
        $this->login('a', $this->user())->assertOk();

        $expired = Carbon::now()->subMinutes((int) config('session.lifetime') + 1)->getTimestamp();
        DB::table('sessions')->where('id', $this->sessionId('a'))->update(['last_activity' => $expired]);

        $this->browser('a', 'GET', '/api/user')->assertUnauthorized();
    }
}
