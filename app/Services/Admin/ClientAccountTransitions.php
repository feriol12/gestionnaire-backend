<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * BE-03 — suspension / réactivation d'un compte client (DM-002, DM-003,
 * Q1–Q8, GOV-05 A1/A3/A4, SEC-01 §5).
 *
 * Une seule transaction, verrous dans un ordre global stable :
 *   1. singleton admin (AdminMfa::requireRecentAuth : grant MFA complet +
 *      preuve ≤300 s revérifiés sous verrou) ;
 *   2. ligne users ciblée (FOR UPDATE) puis CAS statut+version (1 ligne) ;
 *   3. révocation : PAT du modèle exact (tokenable_type ET tokenable_id),
 *      purge des lignes de session « database » en complément de la
 *      révocation logique par auth_version ;
 *   4. audit administratif append-only (motif en clair, accès restreint) ;
 *   5. intention neutre dans l'outbox (aucun envoi ici).
 * Tout échec ⇒ rollback complet : aucun statut sans audit ni outbox.
 *
 * Aucune lecture ni écriture de table financière ; aucun DELETE users.
 */
final class ClientAccountTransitions
{
    public const SUSPEND = 'suspend';

    public const REACTIVATE = 'reactivate';

    /** Table de session client : littérale, car SelectAdminContext réécrit session.table en contexte admin. */
    private const CLIENT_SESSION_TABLE = 'sessions';

    public function __construct(private AdminMfa $mfa) {}

    public function apply(Request $request, string $action, int $userId, string $reason, string $confirmEmail): TransitionOutcome
    {
        [$from, $to] = match ($action) {
            self::SUSPEND => [User::STATUS_ACTIVE, User::STATUS_SUSPENDED],
            self::REACTIVATE => [User::STATUS_SUSPENDED, User::STATUS_ACTIVE],
        };

        return DB::transaction(function () use ($request, $action, $userId, $reason, $confirmEmail, $from, $to) {
            $admin = $this->mfa->requireRecentAuth($request);

            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if (! $user instanceof User) {
                return TransitionOutcome::notFound();
            }

            // Q6 : confirmation sur l'adresse ACTUELLE lue sous verrou ; comparaison
            // exacte (octet à octet, sensible à la casse) après le trim global de
            // TrimStrings. Vérifiée avant l'état : un 422 ne révèle pas le statut.
            if (! hash_equals((string) $user->email, $confirmEmail)) {
                return TransitionOutcome::confirmationMismatch();
            }

            $now = Carbon::now();
            $version = (int) $user->auth_version;
            $current = $user->getAttribute('administrative_status');

            if ($current !== $from) {
                // Q4 : transition redondante ⇒ 409 sans effet métier ; seul le refus
                // est tracé (sans motif : minimisation).
                $this->audit($action, 'refused_redundant', $admin->getKey(), $user->getKey(), $current, $current, $version, $version, null, $now);

                return TransitionOutcome::redundant($to);
            }

            $changed = DB::table('users')
                ->where('id', $user->getKey())
                ->where('administrative_status', $from)
                ->where('auth_version', $version)
                ->update([
                    'administrative_status' => $to,
                    'auth_version' => $version + 1,
                    'suspended_at' => $to === User::STATUS_SUSPENDED ? $now : null,
                    'suspended_by' => $to === User::STATUS_SUSPENDED ? $admin->getKey() : null,
                    // Défense en profondeur : tout cookie « remember » antérieur devient inutilisable.
                    'remember_token' => Str::random(60),
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                throw new TransitionConflict;
            }

            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->delete();
            DB::table(self::CLIENT_SESSION_TABLE)->where('user_id', $user->getKey())->delete();

            $eventId = $this->audit($action, 'succeeded', $admin->getKey(), $user->getKey(), $from, $to, $version, $version + 1, $reason, $now);

            DB::table('notification_outbox')->insert([
                'event_id' => (string) Str::uuid(),
                'correlation_id' => $eventId,
                'type' => $to === User::STATUS_SUSPENDED ? 'client.account_suspended' : 'client.account_reactivated',
                'recipient_user_id' => $user->getKey(),
                'payload' => json_encode(['template' => $to === User::STATUS_SUSPENDED ? 'account_suspended' : 'account_reactivated']),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
            ]);

            return TransitionOutcome::changed((int) $user->getKey(), $to, $now, $eventId);
        });
    }

    private function audit(string $action, string $outcome, int|string $adminId, int|string $userId, ?string $fromStatus, ?string $toStatus, int $before, int $after, ?string $reason, Carbon $at): string
    {
        $eventId = (string) Str::uuid();
        DB::table('admin_audit_events')->insert([
            'event_id' => $eventId,
            'action' => 'client.'.$action,
            'outcome' => $outcome,
            'admin_id' => $adminId,
            'target_user_id' => $userId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'auth_version_before' => $before,
            'auth_version_after' => $after,
            'reason' => $reason,
            'created_at' => $at,
        ]);

        return $eventId;
    }
}
