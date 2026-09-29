<?php

namespace App\Http\Middleware;

use Closure;

class CheckLastActivity
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        // Si pas d'utilisateur, passer (le middleware auth s'en occupera)
        if (!$user) {
            return $next($request);
        }

        // Récupérer ou initialiser last_activity
        $lastActivity = $user->last_activity;

        // Si c'est la première connexion ou last_activity est null
        if (!$lastActivity) {
            // Initialiser last_activity et continuer
            $user->last_activity = now();
            $user->save();
            return $next($request);
        }

        // Vérifier l'inactivité (24 heures)
        // Carbon 3 : diffInMinutes() est signé et fractionnaire (now() vs une
        // date passée => valeur négative). On conserve la sémantique Carbon 2 :
        // minutes entières d'inactivité, en valeur absolue.
        $inactiveMinutes = (int) now()->diffInMinutes($lastActivity, true);

        if ($inactiveMinutes > 1440) { // 1440 minutes = 24 heures
            // Révoquer le token actuel
            $user->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Session expirée après ' . $inactiveMinutes . ' minutes d\'inactivité'
            ], 401);
        }

        // Mettre à jour last_activity
        $user->last_activity = now();
        $user->save();

        return $next($request);
    }
}
