<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserTransitionRequest;
use App\Services\Admin\ClientAccountTransitions;
use App\Services\Admin\TransitionConflict;
use App\Services\Admin\TransitionOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * BE-03 — POST /api/admin/users/{id}/suspend et /reactivate (GOV-05 A1).
 *
 * Réponse 200 strictement limitée à user_id, administrative_status,
 * changed_at, event_id. Jamais de motif, de donnée financière ni d'agrégat.
 * Les 401/403 (session, grant, MFA récente) proviennent d'AdminMfa ; le 419
 * d'AdminCsrf ; le 403 d'origine/Bearer de SelectAdminContext.
 */
final class AdminUserStatusController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store, private'];

    public function __construct(private ClientAccountTransitions $transitions) {}

    public function suspend(AdminUserTransitionRequest $request, string $id): JsonResponse
    {
        return $this->run($request, ClientAccountTransitions::SUSPEND, $id);
    }

    public function reactivate(AdminUserTransitionRequest $request, string $id): JsonResponse
    {
        return $this->run($request, ClientAccountTransitions::REACTIVATE, $id);
    }

    private function run(AdminUserTransitionRequest $request, string $action, string $id): JsonResponse
    {
        $data = $request->validated();

        try {
            $outcome = $this->transitions->apply($request, $action, (int) $id, (string) $data['reason'], (string) $data['confirm_email']);
        } catch (TransitionConflict) {
            return $this->error(409, 'STATE_CONFLICT', 'Transition concurrente : aucune modification appliquée.');
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            // A3 : audit/outbox/statut en échec ⇒ rollback déjà effectué ; réponse
            // expurgée (aucun SQL, motif ni secret). Le journal serveur ne reçoit
            // PAS l'exception brute : une QueryException contient les valeurs liées,
            // donc le motif en clair (SEC-01 S16).
            report(new RuntimeException(sprintf(
                'BE-03 %s transition failed for user #%d (%s, SQLSTATE %s, table %s).',
                $action, (int) $id, $e::class,
                $e instanceof QueryException ? (string) $e->getCode() : '-',
                $e instanceof QueryException && preg_match('/\b(?:into|update|from)\s+`?(\w+)`?/i', $e->getSql(), $m) === 1 ? $m[1] : '-',
            )));

            return $this->error(500, 'TRANSITION_FAILED', 'La transition n\'a pas pu être enregistrée.');
        }

        return match ($outcome->kind) {
            TransitionOutcome::NOT_FOUND => $this->error(404, 'USER_NOT_FOUND', 'Utilisateur introuvable.'),
            TransitionOutcome::CONFIRMATION_MISMATCH => response()->json([
                'message' => 'Les données fournies sont invalides.',
                'errors' => ['confirm_email' => ['La confirmation ne correspond pas au compte ciblé.']],
            ], 422, self::HEADERS),
            TransitionOutcome::REDUNDANT => $outcome->status === 'suspended'
                ? $this->error(409, 'ALREADY_SUSPENDED', 'Ce compte est déjà suspendu.')
                : $this->error(409, 'ALREADY_ACTIVE', 'Ce compte est déjà actif.'),
            TransitionOutcome::CHANGED => response()->json([
                'user_id' => $outcome->userId,
                'administrative_status' => $outcome->status,
                'changed_at' => $outcome->changedAt->copy()->utc()->toIso8601ZuluString(),
                'event_id' => $outcome->eventId,
            ], 200, self::HEADERS),
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status, self::HEADERS);
    }
}
