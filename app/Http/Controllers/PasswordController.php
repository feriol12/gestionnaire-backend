<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * AUTH3-05 — changement de mot de passe d'un compte connecté (session Sanctum).
 *
 * - preuve du mot de passe actuel (règle native current_password) ;
 * - nouveau mot de passe, jeton « remember » renouvelé et jetons personnels du
 *   compte révoqués dans une même transaction ;
 * - la session courante reste ouverte : AuthenticateSession (pile stateful
 *   Sanctum) y enregistre l'empreinte du NOUVEAU mot de passe à la fin de la
 *   requête, à partir de l'utilisateur du garde « web » modifié ici ;
 * - les autres sessions du compte gardent l'ancienne empreinte et sont donc
 *   fermées à leur requête suivante (401), sans manipulation du stockage.
 */
class PasswordController extends Controller
{
    public const UPDATED = 'Votre mot de passe a été mis à jour.';

    public const CURRENT_INVALID = 'Le mot de passe actuel est incorrect.';

    public function update(Request $request): JsonResponse
    {
        // Réservé au navigateur connecté par session (comme la connexion) : un
        // client par jeton personnel ne change pas le mot de passe du compte.
        if (! $request->hasSession() || ! Auth::guard('web')->check()) {
            return response()->json(['message' => 'Session authentication required'], 403);
        }

        $validated = $request->validate([
            'current_password' => 'required|string|current_password:web',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'current_password.current_password' => self::CURRENT_INVALID,
        ]);

        // Même instance que celle relue par AuthenticateSession en fin de requête.
        $user = Auth::guard('web')->user();

        DB::transaction(function () use ($user, $validated) {
            $user->forceFill(['password' => Hash::make($validated['password'])])
                ->setRememberToken(Str::random(60));
            $user->save();

            $user->tokens()->delete();
        });

        // Nouvel identifiant pour la session courante (l'ancien est détruit) :
        // une copie volée de l'ancien cookie ne survit pas au changement.
        $request->session()->regenerate(true);

        return response()->json(['message' => self::UPDATED]);
    }
}
