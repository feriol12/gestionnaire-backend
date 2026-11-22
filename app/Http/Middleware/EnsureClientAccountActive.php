<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Client\ClientAccessGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BE-03 — barrière de statut/version à CHAQUE requête client protégée.
 *
 * Ordre obligatoire : après auth:sanctum et client.identity, AVANT
 * activity.check (aucune écriture last_activity pour un compte refusé) et
 * avant toute ressource métier. L'utilisateur a été relu en base par le
 * garde au début de cette requête : une suspension validée avant ce point
 * est donc toujours vue (point de linéarisation = commit de la transition).
 */
final class EnsureClientAccountActive
{
    public function __construct(private ClientAccessGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            // client.identity doit précéder : fail-closed si la pile est mal composée.
            return response()->json(['code' => 'CLIENT_IDENTITY_REQUIRED'], 403);
        }

        $reason = $this->gate->verdict($request, $user);

        return $reason === null ? $next($request) : $this->gate->deny($request, $user, $reason);
    }
}
