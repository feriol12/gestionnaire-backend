<?php

namespace Tests\Feature;

use App\Services\Admin\ClientSessionPurger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
 * BE-03 / OpenCode F-06 — purge physique complémentaire des sessions client
 * selon SESSION_DRIVER / SESSION_CONNECTION (config admin.client_session,
 * insensible à la réécriture de session.* par SelectAdminContext).
 * La garantie de révocation reste la barrière logique auth_version
 * (ClientSuspensionBarrierTest) ; ce fichier ne qualifie que l'hygiène.
 */
class ClientSessionPurgerTest extends TestCase
{
    use RefreshDatabase;

    private function seedSessions(): void
    {
        foreach ([['s1', 5], ['s2', 5], ['s3', 6]] as [$id, $user]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user, 'payload' => 'x', 'last_activity' => time()]);
        }
    }

    /** @return list<array{sql: string, connection: string}> */
    private function captureSessionDeletes(callable $run): array
    {
        $seen = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$seen) {
            if (str_starts_with(strtolower($q->sql), 'delete from `sessions`')) {
                $seen[] = ['sql' => $q->sql, 'connection' => $q->connectionName];
            }
        });
        $run();

        return $seen;
    }

    public function test_non_database_driver_purges_nothing(): void
    {
        config(['admin.client_session' => ['driver' => 'file', 'connection' => null, 'table' => 'sessions']]);
        $this->seedSessions();

        $deletes = $this->captureSessionDeletes(fn () => app(ClientSessionPurger::class)->purge(5));

        $this->assertSame([], $deletes);
        $this->assertSame(3, DB::table('sessions')->count());
    }

    public function test_default_connection_purge_is_part_of_the_transition_transaction(): void
    {
        config(['admin.client_session' => ['driver' => 'database', 'connection' => null, 'table' => 'sessions']]);
        $this->seedSessions();

        DB::beginTransaction();
        app(ClientSessionPurger::class)->purge(5);
        $this->assertSame(0, DB::table('sessions')->where('user_id', 5)->count());
        DB::rollBack(); // transition annulée ⇒ purge annulée avec elle

        $this->assertSame(2, DB::table('sessions')->where('user_id', 5)->count());

        app(ClientSessionPurger::class)->purge(5);
        $this->assertSame(0, DB::table('sessions')->where('user_id', 5)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', 6)->count(), 'seules les sessions du compte ciblé');
    }

    public function test_explicit_session_connection_is_purged_on_that_connection_after_commit(): void
    {
        // Alias fictif de la même base : seule la connexion utilisée est vérifiée.
        config(['database.connections.client_sessions' => config('database.connections.'.config('database.default'))]);
        config(['admin.client_session' => ['driver' => 'database', 'connection' => 'client_sessions', 'table' => 'sessions']]);

        $seen = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$seen) {
            if (str_starts_with(strtolower($q->sql), 'delete from `sessions`')) {
                $seen[] = $q->connectionName;
            }
        });

        $beforeCommit = null;
        DB::transaction(function () use (&$seen, &$beforeCommit) {
            app(ClientSessionPurger::class)->purge(5);
            $beforeCommit = count($seen);
        });

        $this->assertSame(0, $beforeCommit, 'aucune purge avant le commit de la transition');
        $this->assertSame(['client_sessions'], $seen, 'une purge, après commit, sur la connexion SESSION_CONNECTION');
    }

    public function test_session_connection_purge_is_skipped_when_the_transition_rolls_back(): void
    {
        config(['database.connections.client_sessions' => config('database.connections.'.config('database.default'))]);
        config(['admin.client_session' => ['driver' => 'database', 'connection' => 'client_sessions', 'table' => 'sessions']]);

        $deletes = $this->captureSessionDeletes(function () {
            try {
                DB::transaction(function () {
                    app(ClientSessionPurger::class)->purge(5);
                    throw new \RuntimeException('transition failed');
                });
            } catch (\RuntimeException) {
            }
        });

        $this->assertSame([], $deletes);
    }
}
