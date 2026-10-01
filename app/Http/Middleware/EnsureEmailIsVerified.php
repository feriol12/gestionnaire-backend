<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;

/**
 * AUTH3-04 : même contrôle que le middleware natif « verified »
 * (Illuminate\Auth\Middleware\EnsureEmailIsVerified), mais avec une réponse
 * JSON stable pour l'API (le natif redirige vers une route « verification.notice »
 * inexistante ici ou renvoie un 403 sans code exploitable par le SPA).
 */
class EnsureEmailIsVerified
{
    public const MESSAGE = 'Veuillez vérifier votre adresse email pour accéder à MONEVA.';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail())) {
            return response()->json([
                'message' => self::MESSAGE,
                'code' => 'EMAIL_UNVERIFIED',
            ], 403);
        }

        return $next($request);
    }
}
