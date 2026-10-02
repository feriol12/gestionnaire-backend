<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Tries;

/*
 * PREAUTH-MAIL-01 : renvoi de l'email de vérification hors de la requête HTTP.
 *
 * La file transporte uniquement l'identifiant du compte (null pour une adresse
 * inconnue ; un compte déjà vérifié reçoit aussi une tâche). L'éligibilité est
 * décidée ici, au moment de l'exécution, et l'URL signée n'est construite que
 * dans le worker (jamais sérialisée).
 *
 * Connexion « database » imposée par la classe (voir SendPasswordResetEmailJob).
 */
#[Connection('database')]
#[Tries(3)]
#[Backoff(10)]
class SendVerificationEmailJob implements ShouldQueue
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

        if (! $user || $user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }
}
