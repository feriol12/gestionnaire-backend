<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Password;

/*
 * PREAUTH-MAIL-01 : envoi du lien de réinitialisation hors de la requête HTTP.
 *
 * La file transporte uniquement l'identifiant du compte (null pour une adresse
 * inconnue : la requête publique met toujours une tâche en file, quel que soit
 * le compte). Jeton, hachage bcrypt, rendu du mail et SMTP n'existent que dans
 * le worker ; ni l'email, ni le jeton, ni l'URL ne sont sérialisés.
 *
 * Connexion « database » imposée par la classe : une variable
 * QUEUE_CONNECTION oubliée (sync) ne ramène pas ce travail dans la requête.
 */
#[Connection('database')]
#[Tries(3)]
#[Backoff(10)]
class SendPasswordResetEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?int $userId)
    {
    }

    public function handle(): void
    {
        if ($this->userId === null) {
            return;
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $broker = Password::broker();

        // Broker natif : anti-rafale (recentlyCreatedToken, un seul lien par
        // fenêtre pour un compte), création et hachage du jeton. Une demande en
        // double dans la fenêtre ne produit ni nouveau jeton ni nouveau mail.
        $broker->sendResetLink(
            ['email' => $user->getEmailForPasswordReset()],
            function (CanResetPassword $user, #[\SensitiveParameter] string $token) use ($broker) {
                try {
                    $user->sendPasswordResetNotification($token);
                } catch (\Throwable $e) {
                    // Envoi échoué : le jeton qui vient d'être créé n'a atteint
                    // personne. Le supprimer pour que la nouvelle tentative de la
                    // file ne soit pas bloquée par l'anti-rafale du broker.
                    try {
                        $broker->deleteToken($user);
                    } catch (\Throwable $cleanup) {
                        report($cleanup);
                    }

                    throw $e;
                }

                event(new PasswordResetLinkSent($user));
            }
        );
    }
}
