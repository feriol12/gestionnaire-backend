<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\SerializeClientWrites;
use App\Jobs\SendPasswordResetEmailJob;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\User;
use App\Services\Client\ClientAccessGate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

/*
 * BE-03 — barrière client (statut + auth_version), refus du login et du reset,
 * sérialisation des écritures, migration. Données exclusivement fictives.
 *
 * Les transitions sont ici appliquées DIRECTEMENT en base, à l'identique de
 * ClientAccountTransitions (statut, version+1, PAT du modèle supprimés) : ce
 * fichier qualifie la barrière indépendamment du flux admin MFA, qui est
 * couvert de bout en bout par le banc tests/AdminSuspension (une application
 * neuve par requête, imposée par SelectAdminContext).
 */
class ClientSuspensionBarrierTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = 'http://localhost:5173';

    private const PASSWORD = 'secret-password-123';

    private array $jars = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cors.allowed_origins' => [self::SPA]]);
        // Sessions persistantes entre requêtes simulées (phpunit.xml : array).
        $this->useSessionDriver('file');

        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    // ------------------------------------------------------------------ helpers

    private function useSessionDriver(string $driver): void
    {
        config(['session.driver' => $driver]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    private function freshRequestState(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->unencryptedCookies = [];
        $this->defaultHeaders = [];
    }

    private function browser(string $name, string $method, string $uri, array $data = []): TestResponse
    {
        $this->freshRequestState();
        $jar = $this->jars[$name] ?? [];
        $headers = ['Origin' => self::SPA, 'Referer' => self::SPA.'/', 'Accept' => 'application/json'];
        if (isset($jar['XSRF-TOKEN'])) {
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

    private function login(string $name, string $email, string $password = self::PASSWORD): TestResponse
    {
        $this->browser($name, 'GET', '/sanctum/csrf-cookie')->assertNoContent();

        return $this->browser($name, 'POST', '/api/login', ['email' => $email, 'password' => $password]);
    }

    private function withPat(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->freshRequestState();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => self::PASSWORD] + $attributes);
    }

    /** Même effet durable que ClientAccountTransitions (sans audit/outbox, hors périmètre ici). */
    private function transitionInDatabase(User $user, string $to): void
    {
        DB::transaction(function () use ($user, $to) {
            $row = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            DB::table('users')->where('id', $user->id)->update([
                'administrative_status' => $to,
                'auth_version' => (int) $row->auth_version + 1,
                'suspended_at' => $to === 'suspended' ? now() : null,
                'suspended_by' => $to === 'suspended' ? 1 : null,
            ]);
            PersonalAccessToken::query()->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)->delete();
        });
    }

    private function expensePayload(): array
    {
        return ['description' => 'Dépense fictive', 'amount' => 1500, 'category' => 'Transport', 'date' => now()->toDateString()];
    }

    // ----------------------------------------------- T15 : inventaire des routes

    public function test_every_authenticated_client_route_runs_the_barrier_before_activity_and_business(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }
            $uri = $route->uri();
            if ($uri === 'api/logout') {
                // Volontaire : la fermeture d'une session/PAT reste toujours possible.
                $this->assertNotContains('client.active', $middleware);

                continue;
            }
            $checked++;
            $pos = array_flip($middleware);
            $this->assertArrayHasKey('client.active', $pos, $uri);
            $this->assertGreaterThan($pos['client.identity'], $pos['client.active'], $uri);
            if (isset($pos['activity.check'])) {
                $this->assertLessThan($pos['activity.check'], $pos['client.active'], $uri);
            }
            $mutating = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
            if ($mutating) {
                $this->assertArrayHasKey('client.write', $pos, $uri.' écrit sans sérialisation');
            }
        }
        $this->assertGreaterThan(15, $checked);
    }

    // ------------------------------------------- T06 : sessions file et database

    public function test_suspension_refuses_an_existing_file_session(): void
    {
        $this->useSessionDriver('file');
        $this->assertExistingSessionIsRefusedAfterSuspension();
    }

    public function test_suspension_refuses_an_existing_database_session(): void
    {
        $this->useSessionDriver('database');
        $this->assertExistingSessionIsRefusedAfterSuspension();
    }

    private function assertExistingSessionIsRefusedAfterSuspension(): void
    {
        $user = $this->user();
        $this->login('A', $user->email)->assertOk();
        $this->browser('A', 'GET', '/api/user')->assertOk();
        $this->browser('A', 'POST', '/api/expenses', $this->expensePayload())->assertCreated();
        $lastActivity = $user->fresh()->last_activity;

        $this->travel(5)->minutes();
        $this->transitionInDatabase($user, 'suspended');

        $this->browser('A', 'GET', '/api/user')->assertForbidden()->assertExactJson(['message' => 'Accès refusé.', 'code' => 'CLIENT_SUSPENDED']);
        // La session a été fermée par le refus : la requête suivante n'est plus authentifiée.
        $this->browser('A', 'POST', '/api/expenses', $this->expensePayload())->assertUnauthorized();
        $this->browser('A', 'GET', '/api/expenses')->assertUnauthorized();

        $this->assertSame(1, Expense::query()->where('user_id', $user->id)->count(), 'finances conservées, aucune nouvelle écriture');
        $this->assertEquals($lastActivity, $user->fresh()->last_activity, 'activity.check ne doit pas s\'exécuter pour un compte refusé');
    }

    // ------------------------------------------------------------ T07 : PAT

    public function test_a_revoked_pat_is_refused_after_suspension(): void
    {
        $user = $this->user();
        $pat = $user->createToken('legacy')->plainTextToken;
        $this->withPat($pat, 'GET', '/api/expenses')->assertOk();

        $this->transitionInDatabase($user, 'suspended');

        $this->withPat($pat, 'GET', '/api/expenses')->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_status_barrier_alone_refuses_a_still_existing_pat_and_burns_it(): void
    {
        $user = $this->user();
        $pat = $user->createToken('legacy')->plainTextToken;
        // Statut suspendu SANS suppression du PAT : la barrière ne dépend pas de la purge.
        DB::table('users')->where('id', $user->id)->update(['administrative_status' => 'suspended', 'auth_version' => 2]);

        $this->withPat($pat, 'POST', '/api/expenses', $this->expensePayload())->assertForbidden()->assertJsonPath('code', 'CLIENT_SUSPENDED');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_pat_revocation_targets_the_exact_morph_type_and_id(): void
    {
        $user = $this->user();
        $other = $this->user();
        $user->createToken('mine');
        $otherPat = $other->createToken('other')->plainTextToken;
        // Jeton d'un autre type polymorphique portant le même tokenable_id (collision fictive).
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\AdminIdentity', 'tokenable_id' => $user->id,
            'name' => 'foreign-type', 'token' => hash('sha256', Str::random(40)), 'abilities' => '["*"]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->transitionInDatabase($user, 'suspended');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\AdminIdentity')->count());
        $this->withPat($otherPat, 'GET', '/api/expenses')->assertOk();
    }

    // ------------------------------------------- T08 : aucune résurrection

    public function test_reactivation_never_resurrects_old_sessions_or_pats(): void
    {
        $this->useSessionDriver('file');
        $user = $this->user();
        $this->login('old', $user->email)->assertOk();
        $pat = $user->createToken('legacy')->plainTextToken;

        $this->transitionInDatabase($user, 'suspended');
        $this->transitionInDatabase($user, 'active');
        $this->assertSame(3, (int) $user->fresh()->auth_version);

        $this->browser('old', 'GET', '/api/user')->assertUnauthorized();
        $this->withPat($pat, 'GET', '/api/user')->assertUnauthorized();

        // Nouveau login explicite : accès rétabli, lié à la version courante.
        $this->login('new', $user->email)->assertOk();
        $this->browser('new', 'GET', '/api/user')->assertOk();
        $this->browser('new', 'POST', '/api/expenses', $this->expensePayload())->assertCreated();
    }

    public function test_a_session_without_bound_version_is_refused(): void
    {
        $user = $this->user();
        // Session navigateur antérieure à BE-03 : authentifiée mais sans version liée.
        $this->freshRequestState();
        $this->actingAs($user, 'web')
            ->withHeaders(['Origin' => self::SPA, 'Referer' => self::SPA.'/'])
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    // ------------------------------------------------- A1 : login générique

    public function test_suspended_login_gets_the_generic_401_without_any_session(): void
    {
        $suspended = $this->user(['email' => 'suspendu@example.test']);
        DB::table('users')->where('id', $suspended->id)->update(['administrative_status' => 'suspended']);
        $unverifiedSuspended = $this->user(['email' => 'suspendu-nv@example.test', 'email_verified_at' => null]);
        DB::table('users')->where('id', $unverifiedSuspended->id)->update(['administrative_status' => 'suspended']);
        $this->user(['email' => 'actif@example.test']);

        $wrong = $this->login('w', 'actif@example.test', 'not-the-password');
        $s = $this->login('s', 'suspendu@example.test');
        $snv = $this->login('snv', 'suspendu-nv@example.test');

        $wrong->assertUnauthorized();
        $s->assertUnauthorized();
        $snv->assertUnauthorized();
        $this->assertSame($wrong->json(), $s->json());
        $this->assertSame($wrong->json(), $snv->json(), 'ni le statut ni la vérification ne doivent fuir');
        $this->browser('s', 'GET', '/api/user')->assertUnauthorized();
    }

    public function test_client_payloads_never_expose_administrative_metadata(): void
    {
        $user = $this->user();
        $login = $this->login('A', $user->email)->assertOk();
        $me = $this->browser('A', 'GET', '/api/user')->assertOk();

        foreach (['administrative_status', 'auth_version', 'suspended_at', 'suspended_by'] as $field) {
            $this->assertArrayNotHasKey($field, $login->json('user'));
            $this->assertArrayNotHasKey($field, $me->json());
        }
    }

    // --------------------------------------------------- T09 / Q5 : reset

    public function test_no_reset_link_is_issued_for_a_suspended_account(): void
    {
        Notification::fake();
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update(['administrative_status' => 'suspended']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        (new SendPasswordResetEmailJob($user->id))->handle();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_a_valid_reset_token_has_no_effect_on_a_suspended_account(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        $hash = $user->fresh()->password;
        DB::table('users')->where('id', $user->id)->update(['administrative_status' => 'suspended']);

        $suspended = $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456',
        ]);
        $invalid = $this->postJson('/api/reset-password', [
            'token' => 'not-a-token', 'email' => $user->email,
            'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456',
        ]);

        $suspended->assertStatus(422);
        $this->assertSame($invalid->json(), $suspended->json(), 'même réponse publique qu\'un lien invalide');
        $this->assertSame($hash, $user->fresh()->password, 'mot de passe inchangé');
        $this->assertSame(0, DB::table('password_reset_tokens')->count(), 'jeton consommé');
        $this->login('A', $user->email, 'brand-new-password-456')->assertUnauthorized();
    }

    public function test_reset_still_works_for_an_active_account(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456',
        ])->assertOk();
    }

    // ------------------------------------- A4 : sérialisation des écritures

    private function runWriteGate(User $requestUser, string $method = 'POST'): array
    {
        $request = Request::create('/api/expenses', $method);
        $request->setUserResolver(fn () => $requestUser);
        $requestUser->withAccessToken(new class extends PersonalAccessToken {});
        $called = false;
        $level = null;
        $response = $this->app->make(SerializeClientWrites::class)->handle($request, function () use (&$called, &$level) {
            $called = true;
            $level = DB::transactionLevel();

            return response()->json(['ok' => true]);
        });

        return [$response, $called, $level];
    }

    public function test_the_write_gate_runs_the_action_under_the_user_row_lock(): void
    {
        $user = $this->user();
        $baseline = DB::transactionLevel();
        [$response, $called, $level] = $this->runWriteGate($user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($called);
        $this->assertSame($baseline + 1, $level, 'action exécutée dans la transaction qui tient le verrou users');
    }

    public function test_the_write_gate_rejects_a_suspension_committed_after_the_barrier(): void
    {
        $user = $this->user();                     // vu « active » v1 par la barrière
        DB::table('users')->where('id', $user->id)->update(['administrative_status' => 'suspended', 'auth_version' => 2]);

        [$response, $called] = $this->runWriteGate($user);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($called, 'aucune écriture métier');
    }

    public function test_the_write_gate_rejects_an_intervening_suspend_and_reactivate(): void
    {
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update(['auth_version' => 3]); // actif, version changée

        [$response, $called] = $this->runWriteGate($user);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($called);
    }

    public function test_reads_are_not_locked_by_the_write_gate(): void
    {
        $user = $this->user();
        $baseline = DB::transactionLevel();
        [$response, $called, $level] = $this->runWriteGate($user, 'GET');

        $this->assertTrue($called);
        $this->assertSame($baseline, $level);
    }

    public function test_budget_and_expense_mutations_are_refused_without_effect_once_suspended(): void
    {
        $user = $this->user();
        $pat = $user->createToken('legacy')->plainTextToken;
        $expense = Expense::query()->create(['user_id' => $user->id] + $this->expensePayload());
        $budget = Budget::query()->create(['user_id' => $user->id, 'month' => now()->startOfMonth()->toDateString(), 'amount' => 50000]);

        DB::table('users')->where('id', $user->id)->update(['administrative_status' => 'suspended', 'auth_version' => 2]);

        // Premier refus : 403 et PAT brûlé ; les suivants : 401. Aucun effet dans tous les cas.
        $this->withPat($pat, 'PUT', '/api/expenses/'.$expense->id, ['amount' => 1])->assertForbidden();
        $this->withPat($pat, 'DELETE', '/api/expenses/'.$expense->id)->assertUnauthorized();
        $this->withPat($pat, 'DELETE', '/api/budgets/'.$budget->id)->assertUnauthorized();

        $this->assertSame('1500.00', (string) $expense->fresh()->amount);
        $this->assertNotNull(Budget::query()->find($budget->id));
    }

    // ---------------------------------------------------------- T17 : schéma

    public function test_migration_defaults_and_status_constraint(): void
    {
        foreach (['administrative_status', 'auth_version', 'suspended_at', 'suspended_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), $column);
        }
        $this->assertTrue(Schema::hasTable('admin_audit_events'));
        $this->assertTrue(Schema::hasTable('notification_outbox'));

        $id = DB::table('users')->insertGetId([
            'name' => 'Défauts', 'email' => 'defauts@example.test', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('users')->find($id);
        $this->assertSame('active', $row->administrative_status);
        $this->assertSame(1, (int) $row->auth_version);
        $this->assertNull($row->suspended_at);
        $this->assertNull($row->suspended_by);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->expectException(QueryException::class);
            DB::table('users')->where('id', $id)->update(['administrative_status' => 'banned']);
        }
    }

    public function test_status_columns_are_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill(['name' => 'x', 'administrative_status' => 'suspended', 'auth_version' => 99, 'suspended_by' => 1]);

        $this->assertNull($user->getAttribute('administrative_status'));
        $this->assertNull($user->getAttribute('auth_version'));
        $this->assertNull($user->getAttribute('suspended_by'));
    }

    public function test_gate_is_fail_closed_on_missing_status(): void
    {
        $user = (new User)->forceFill(['id' => 9, 'auth_version' => 1]);
        $user->withAccessToken(new TransientToken);
        $request = Request::create('/api/user');

        $this->assertSame(ClientAccessGate::DENY_SUSPENDED, $this->app->make(ClientAccessGate::class)->verdict($request, $user));
    }
}
