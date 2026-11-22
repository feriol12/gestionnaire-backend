<?php

namespace App\Services\Client;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * BE-03 (Q1, Q2, I08) — décision unique « ce principal client peut-il encore
 * agir ? », partagée par la barrière de requête et la synchronisation des
 * écritures financières.
 *
 * - Statut : tout autre état que 'active' est refusé, quel que soit le
 *   SESSION_DRIVER (file, database, array…) et pour les PAT Sanctum.
 * - Version : une session navigateur doit porter la version d'accès
 *   enregistrée au login ; absente ou différente ⇒ session révoquée. Les PAT
 *   sont supprimés dans la transaction de transition : leur existence vaut
 *   preuve de non-révocation.
 *
 * Codes (GOV-05 §4, SEC-01 §4) : 401 pour un accès révoqué/obsolète, 403
 * CLIENT_SUSPENDED pour un principal encore authentifié mais suspendu.
 */
final class ClientAccessGate
{
    public const SESSION_VERSION_KEY = 'client_auth_version';

    public const DENY_REVOKED = 'revoked';

    public const DENY_SUSPENDED = 'suspended';

    /** Raison du refus, ou null si l'accès reste autorisé. */
    public function verdict(Request $request, User $user, ?string $status = null, ?int $version = null): ?string
    {
        $status ??= $user->getAttribute('administrative_status');
        $version ??= $user->getAttribute('auth_version');

        if ($status !== User::STATUS_ACTIVE) {
            return self::DENY_SUSPENDED;
        }

        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            return null;
        }

        if (! $request->hasSession()) {
            return self::DENY_REVOKED;
        }

        $bound = $request->session()->get(self::SESSION_VERSION_KEY);

        return is_int($bound) && is_int($version) && $bound === $version ? null : self::DENY_REVOKED;
    }

    /** Ferme l'accès courant (session ou PAT) puis produit la réponse de refus. */
    public function deny(Request $request, User $user, string $reason): JsonResponse
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            // logoutCurrentDevice : pas d'écriture remember_token sur users.
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $reason === self::DENY_SUSPENDED
            ? response()->json(['message' => 'Accès refusé.', 'code' => 'CLIENT_SUSPENDED'], 403)
            : response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
