<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * BE-04 — unique écrivain du journal administratif admin_audit_events.
 *
 * - Append-only : uniquement des INSERT (la base refuse UPDATE et DELETE,
 *   migration 2026_11_23_000001) ; aucune méthode de lecture, de
 *   modification ou de purge (consultation : BE-07 ; rétention : Q8).
 * - D2 : seuls les résultats « succeeded » et « refused_redundant ».
 * - D3 / Q3 : motif en clair uniquement pour une transition réussie ; un refus
 *   n'enregistre jamais de motif ; aucun champ IP, User-Agent ni identifiant de
 *   requête.
 * - A3 : doit être appelé DANS la transaction de la transition, pour que
 *   statut, audit et outbox soient validés ou annulés ensemble.
 */
final class AdminAuditLog
{
    public const SUCCEEDED = 'succeeded';

    public const REFUSED_REDUNDANT = 'refused_redundant';

    private const OUTCOMES = [self::SUCCEEDED, self::REFUSED_REDUNDANT];

    /** Ajoute une ligne et renvoie son event_id (UUID opaque). */
    public function record(
        string $action,
        string $outcome,
        int|string $adminId,
        int|string $targetUserId,
        ?string $fromStatus,
        ?string $toStatus,
        int $versionBefore,
        int $versionAfter,
        ?string $reason,
        Carbon $at,
    ): string {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Audit outcome not allowed by BE-04 D2.');
        }
        if ($outcome !== self::SUCCEEDED && $reason !== null) {
            throw new InvalidArgumentException('Only a succeeded transition may carry a reason (minimisation).');
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The administrative audit must be written inside the transition transaction.');
        }

        $eventId = (string) Str::uuid();
        DB::table('admin_audit_events')->insert([
            'event_id' => $eventId,
            'action' => $action,
            'outcome' => $outcome,
            'admin_id' => $adminId,
            'target_user_id' => $targetUserId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'auth_version_before' => $versionBefore,
            'auth_version_after' => $versionAfter,
            'reason' => $reason,
            'created_at' => $at,
        ]);

        return $eventId;
    }
}
