<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckLastActivity
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        // Si pas d'utilisateur, passer (le middleware auth s'en occupera)
        if (!$user) {
            return $next($request);
        }

        // Exclure certaines routes
        $excludedRoutes = ['api/login', 'api/logout', 'api/register'];
        if (in_array($request->path(), $excludedRoutes)) {
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

        // Vérifier l'inactivité (5 minutes)
        $inactiveMinutes = now()->diffInMinutes($lastActivity);

        \Log::info('Activity check', [
            'user_id' => $user->id,
            'last_activity' => $lastActivity,
            'inactive_minutes' => $inactiveMinutes,
            'threshold' => 5
        ]);

        if ($inactiveMinutes > 5) {
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
