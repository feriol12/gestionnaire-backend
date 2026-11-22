<?php

namespace Tests\Feature;

use App\Services\Admin\AdminAuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/*
 * BE-04 — frontières du journal administratif (A09, A11, D2, D3, BE-05/BE-07).
 * Aucune écriture en base : les gardes refusent AVANT tout INSERT.
 */
class AdminAuditBoundaryTest extends TestCase
{
    /** @return list<string> chemins relatifs des fichiers PHP de l'application qui citent la table */
    private function filesReferencingAuditTable(): array
    {
        $hits = [];
        foreach (['app', 'routes', 'config', 'bootstrap'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'admin_audit_events')) {
                    $hits[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
                }
            }
        }
        sort($hits);

        return $hits;
    }

    public function test_admin_audit_log_is_the_only_application_code_touching_the_table(): void
    {
        // A09 : aucune autre écriture, lecture, purge ni modèle Eloquent (lecture = BE-07).
        $this->assertSame(['app/Services/Admin/AdminAuditLog.php'], $this->filesReferencingAuditTable());
    }

    public function test_no_route_exposes_the_audit_log(): void
    {
        // Consultation de l'audit : BE-07, hors périmètre.
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_contains($u, 'audit'))->values()->all();
        $this->assertSame([], $uris);
        // Les routes admin ne sont enregistrées que si ADMIN_MFA_ENABLED : contrôle du fichier lui-même.
        foreach (glob(base_path('routes/*.php')) as $file) {
            $this->assertStringNotContainsString('audit', strtolower((string) file_get_contents($file)), basename($file));
        }
    }

    public function test_only_the_two_approved_outcomes_are_accepted(): void
    {
        foreach (['refused_mfa', 'refused_not_found', 'refused_validation', 'throttled', 'failed', ''] as $outcome) {
            try {
                app(AdminAuditLog::class)->record('client.suspend', $outcome, 1, 2, 'active', 'active', 1, 1, null, Carbon::now());
                $this->fail("Outcome '$outcome' must be refused (D2).");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_refusal_never_carries_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(AdminAuditLog::class)->record('client.suspend', AdminAuditLog::REFUSED_REDUNDANT, 1, 2, 'suspended', 'suspended', 2, 2, 'motif interdit', Carbon::now());
    }

    public function test_the_audit_cannot_be_written_outside_a_transaction(): void
    {
        // A3 : hors transaction, l'audit ne pourrait pas être annulé avec la transition.
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);
        app(AdminAuditLog::class)->record('client.suspend', AdminAuditLog::SUCCEEDED, 1, 2, 'active', 'suspended', 1, 2, 'Motif fictif', Carbon::now());
    }

    public function test_no_scheduled_or_console_purge_exists(): void
    {
        // A11 / Q8 : aucune purge tant qu'aucune politique de rétention n'est approuvée.
        $console = (string) file_get_contents(app_path('Console/Kernel.php'));
        $this->assertStringNotContainsString('audit', strtolower($console));
        $this->assertSame([], array_values(array_filter(
            array_keys(\Illuminate\Support\Facades\Artisan::all()),
            fn ($name) => str_contains($name, 'audit'),
        )));
    }
}
