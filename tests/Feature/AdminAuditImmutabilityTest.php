<?php

namespace Tests\Feature;

use App\Services\Admin\AdminAuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/*
 * BE-04 (D1) — admin_audit_events est append-only EN BASE : UPDATE et DELETE
 * refusés pour tout compte SQL, INSERT et rollback transactionnel inchangés.
 * Exécuté sur le moteur de la suite (MySQL / MariaDB : CI MariaDB 10.11).
 * Scénarios A01, A02, A03, A04, A05 (partie base) et D3.
 */
class AdminAuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $outcome = AdminAuditLog::SUCCEEDED, ?string $reason = 'Motif fictif BE-04'): string
    {
        return DB::transaction(fn () => app(AdminAuditLog::class)->record(
            'client.suspend', $outcome, 1, 42, 'active', $outcome === AdminAuditLog::SUCCEEDED ? 'suspended' : 'active',
            1, $outcome === AdminAuditLog::SUCCEEDED ? 2 : 1, $outcome === AdminAuditLog::SUCCEEDED ? $reason : null, Carbon::now(),
        ));
    }

    private function row(string $eventId): array
    {
        return (array) DB::table('admin_audit_events')->where('event_id', $eventId)->first();
    }

    private function assertRefused(callable $statement): void
    {
        try {
            $statement();
            $this->fail('The database accepted a forbidden statement on admin_audit_events.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_insert_through_the_single_writer_is_allowed(): void
    {
        $id = $this->record();

        $row = $this->row($id);
        $this->assertSame(['client.suspend', 'succeeded', 42, 'Motif fictif BE-04'], [$row['action'], $row['outcome'], (int) $row['target_user_id'], $row['reason']]);
    }

    public function test_update_is_refused_and_leaves_the_row_untouched(): void
    {
        $id = $this->record();
        $before = $this->row($id);

        $this->assertRefused(fn () => DB::table('admin_audit_events')->where('event_id', $id)->update(['reason' => 'réécrit']));
        $this->assertRefused(fn () => DB::table('admin_audit_events')->where('event_id', $id)->update(['outcome' => 'refused_redundant', 'to_status' => 'active']));
        $this->assertRefused(fn () => DB::update('UPDATE admin_audit_events SET admin_id = admin_id + 1'));

        $this->assertSame($before, $this->row($id));
    }

    public function test_delete_is_refused_and_keeps_every_row(): void
    {
        $first = $this->record();
        $second = $this->record(AdminAuditLog::REFUSED_REDUNDANT);

        $this->assertRefused(fn () => DB::table('admin_audit_events')->where('event_id', $first)->delete());
        $this->assertRefused(fn () => DB::delete('DELETE FROM admin_audit_events'));

        $this->assertSame(2, DB::table('admin_audit_events')->whereIn('event_id', [$first, $second])->count());
    }

    public function test_a_rolled_back_transition_leaves_no_audit_row(): void
    {
        // A04 : un échec après l'écriture d'audit (ici une intention outbox invalide)
        // annule aussi l'audit — le rollback n'exécute pas de DELETE, les triggers
        // n'interfèrent pas.
        $before = DB::table('admin_audit_events')->count();
        try {
            DB::transaction(function () {
                app(AdminAuditLog::class)->record('client.suspend', AdminAuditLog::SUCCEEDED, 1, 7, 'active', 'suspended', 1, 2, 'Motif fictif', Carbon::now());
                DB::table('notification_outbox')->insert(['event_id' => 'not-a-uuid-and-missing-columns']);
            });
            $this->fail('The invalid outbox intention should have failed.');
        } catch (QueryException) {
        }

        $this->assertSame($before, DB::table('admin_audit_events')->count());

        try {
            DB::transaction(function () {
                app(AdminAuditLog::class)->record('client.suspend', AdminAuditLog::SUCCEEDED, 1, 7, 'active', 'suspended', 1, 2, 'Motif fictif', Carbon::now());
                throw new RuntimeException('transition failed after audit');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($before, DB::table('admin_audit_events')->count());
    }

    public function test_triggers_are_installed_for_update_and_delete(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Trigger catalogue checked on MySQL/MariaDB only.');
        }

        $triggers = collect(DB::select(
            'SELECT trigger_name AS name, event_manipulation AS event, action_timing AS timing FROM information_schema.triggers WHERE event_object_schema = DATABASE() AND event_object_table = ?',
            ['admin_audit_events'],
        ))->map(fn ($t) => [strtolower($t->name), $t->event, $t->timing])->sortBy(0)->values()->all();

        $this->assertSame([
            ['admin_audit_events_no_delete', 'DELETE', 'BEFORE'],
            ['admin_audit_events_no_update', 'UPDATE', 'BEFORE'],
        ], $triggers);
    }

    public function test_no_tracking_column_was_added(): void
    {
        // D3 : aucune IP, aucun User-Agent, aucun identifiant de requête ; schéma BE-03 inchangé.
        $this->assertSame(
            ['action', 'admin_id', 'auth_version_after', 'auth_version_before', 'created_at', 'event_id', 'from_status', 'id', 'outcome', 'reason', 'target_user_id', 'to_status'],
            collect(Schema::getColumnListing('admin_audit_events'))->sort()->values()->all(),
        );
    }
}
