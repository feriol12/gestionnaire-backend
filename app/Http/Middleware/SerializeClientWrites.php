<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Client\ClientAccessGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * BE-03 (A4, SEC-01 S10/§5.7) — synchronisation des mutations clientes avec
 * une suspension concurrente.
 *
 * Toute requête non sûre (POST/PUT/PATCH/DELETE) exécute son action dans une
 * transaction qui verrouille D'ABORD la ligne users du client (FOR UPDATE),
 * relit statut et version, puis seulement écrit dans les tables métier.
 * La transition administrative verrouille la même ligne avant de la modifier
 * et ne touche jamais aux tables financières : ordre global stable
 * (users → tables métier), donc pas d'interblocage.
 *
 *  - écriture gagnante : elle se termine et commit avant la suspension ;
 *  - suspension gagnante : l'écriture attend le verrou, relit 'suspended'
 *    (ou une version changée) et est rejetée sans aucun effet.
 *
 * Les lectures (GET/HEAD/OPTIONS) ne prennent aucun verrou : déjà admises par
 * la barrière, elles peuvent se terminer (contrat BE-03 §3.3).
 */
final class SerializeClientWrites
{
    public function __construct(private ClientAccessGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['code' => 'CLIENT_IDENTITY_REQUIRED'], 403);
        }

        $denied = null;
        $response = DB::transaction(function () use ($request, $next, $user, &$denied) {
            $row = DB::table('users')->where('id', $user->getKey())->lockForUpdate()
                ->first(['administrative_status', 'auth_version']);

            if ($row === null) {
                $denied = ClientAccessGate::DENY_REVOKED;
            } elseif ($row->administrative_status !== User::STATUS_ACTIVE) {
                $denied = ClientAccessGate::DENY_SUSPENDED;
            } elseif ((int) $row->auth_version !== (int) $user->getAttribute('auth_version')) {
                // Suspension puis réactivation intercalées depuis la barrière.
                $denied = ClientAccessGate::DENY_REVOKED;
            } else {
                $denied = $this->gate->verdict($request, $user, $row->administrative_status, (int) $row->auth_version);
            }

            return $denied === null ? $next($request) : null;
        });

        return $denied === null ? $response : $this->gate->deny($request, $user, $denied);
    }
}
