<?php

namespace App\Http\Controllers;

use App\Jobs\SendPasswordResetEmailJob;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

/*
 * AUTH3-03 — récupération de mot de passe via le Password Broker Laravel
 * (jetons hachés dans password_reset_tokens, expiration et anti-rafale du
 * broker, notification ResetPassword).
 *
 * PREAUTH-MAIL-01 : la demande de lien est traitée par une tâche en file
 * (connexion database) ; voir sendResetLink et SendPasswordResetEmailJob.
 *
 * Aucune réponse ne révèle si un compte existe, et une réinitialisation
 * réussie ne connecte personne (ni session, ni jeton personnel). Elle ferme
 * toutes les sessions existantes et révoque les jetons personnels du compte.
 */
class PasswordResetController extends Controller
{
    public const LINK_REQUESTED = 'Si cette adresse correspond à un compte, un lien de réinitialisation vient d\'être envoyé.';

    public const RESET_DONE = 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.';

    public const INVALID_LINK = 'Ce lien de réinitialisation est invalide ou a expiré. Veuillez refaire une demande.';

    public function sendResetLink(Request $request, Timebox $timebox): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

        // PREAUTH-MAIL-01 : la requête ne fait que chercher le compte et mettre
        // UNE tâche en file, compte connu ou non (identifiant ou null). Jeton,
        // bcrypt, mail et SMTP sont dans le worker (SendPasswordResetEmailJob) ;
        // la Timebox native égalise le reste du chemin.
        $timebox->call(function () use ($request) {
            try {
                $user = Password::broker()->getUser(['email' => $request->input('email')]);

                Bus::dispatch(new SendPasswordResetEmailJob($user?->getKey()));
            } catch (\Throwable $e) {
                // Échec d'infrastructure (lecture, insertion en file) : signalé
                // côté serveur, même réponse publique (pas d'énumération).
                report($e);
            }
        }, (int) config('auth.timebox_duration', 200000));

        return response()->json(['message' => self::LINK_REQUESTED]);
    }

    public function reset(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'token' => 'required|string',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset($credentials, function (User $user, string $password) {
            // AUTH3-05 : changement d'identifiants = révocation des jetons
            // personnels de CE compte (jetons hérités de l'ancien modèle
            // navigateur), dans la même transaction que le nouveau mot de passe.
            // Les sessions existantes tombent d'elles-mêmes : AuthenticateSession
            // compare l'empreinte du mot de passe stockée en session.
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])
                    ->setRememberToken(Str::random(60));
                $user->save();

                $user->tokens()->delete();
            });

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Jeton invalide, expiré, déjà utilisé ou adresse inconnue : même
            // réponse dans tous les cas.
            return response()->json([
                'message' => self::INVALID_LINK,
                'errors' => ['token' => [self::INVALID_LINK]],
            ], 422);
        }

        return response()->json(['message' => self::RESET_DONE]);
    }
}
