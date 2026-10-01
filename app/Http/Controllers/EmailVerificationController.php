<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AUTH3-04 : vérification d'adresse email (mécanisme natif Laravel).
 *
 * Le lien envoyé par email est une URL signée temporaire (VerifyEmail +
 * URL::temporarySignedRoute) qui lie l'identifiant du compte, le hash de son
 * email et l'expiration. Il fonctionne sans session (autre onglet, autre
 * navigateur) et ne connecte jamais l'utilisateur : il renvoie vers le SPA.
 */
class EmailVerificationController extends Controller
{
    public const RESEND_REQUESTED = 'Si cette adresse correspond à un compte nécessitant une vérification, un nouvel email vient d\'être envoyé.';

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        // Signature relative (chemin + paramètres) : indépendante de l'hôte et du
        // schéma vus derrière un proxy ; toute modification de l'id, du hash ou de
        // l'expiration invalide la signature.
        if (! $request->hasValidSignature(false)) {
            return $this->toFrontend('/verify-email?verification=invalid');
        }

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->toFrontend('/verify-email?verification=invalid');
        }

        // Lien valide déjà utilisé : succès idempotent, sans nouvel événement.
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Pas de connexion automatique : l'utilisateur se connecte ensuite.
        return $this->toFrontend('/login?email_verified=1');
    }

    public function resend(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

        // Réponse identique que le compte existe, soit déjà vérifié ou non (pas
        // d'énumération) ; seul un compte existant non vérifié reçoit un email.
        try {
            $user = User::where('email', $request->input('email'))->first();

            if ($user && ! $user->hasVerifiedEmail()) {
                $user->sendEmailVerificationNotification();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => self::RESEND_REQUESTED]);
    }

    // Redirection uniquement vers l'origine configurée du SPA (aucune URL issue
    // de la requête : pas de redirection ouverte).
    private function toFrontend(string $path): RedirectResponse
    {
        return redirect()->away(config('app.frontend_url').$path);
    }
}
