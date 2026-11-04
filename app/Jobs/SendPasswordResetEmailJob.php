<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

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
 *
 * LWS-BE-06A : le résultat du broker n'est plus ignoré. Une tentative
 * interrompue après la création du jeton (processus tué) laisse un jeton
 * récent ; la nouvelle tentative recevait RESET_THROTTLED et se terminait
 * « avec succès » sans aucun mail. Désormais (priorité à la remise) :
 *  - 1re tentative + anti-rafale : déduplication inchangée (fin normale) ;
 *  - nouvelle tentative + anti-rafale : la tâche est relâchée pour toute la
 *    fenêtre d'anti-rafale du broker utilisé, puis renvoie un lien ;
 *  - dernière tentative encore bloquée ou statut inattendu : échec explicite
 *    et visible (failed_jobs), jamais un succès silencieux.
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

        // Broker par défaut de l'application, et SA fenêtre d'anti-rafale.
        [$brokerName, $throttle] = $this->brokerSettings();

        $broker = Password::broker($brokerName);

        // Broker natif : anti-rafale (recentlyCreatedToken, un seul lien par
        // fenêtre pour un compte), création et hachage du jeton. Une demande en
        // double dans la fenêtre ne produit ni nouveau jeton ni nouveau mail.
        $status = $broker->sendResetLink(
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

        match ($status) {
            // INVALID_USER : compte introuvable par son email (course) : fin
            // neutre, aucune différence observable (anti-énumération).
            PasswordBroker::RESET_LINK_SENT, PasswordBroker::INVALID_USER => null,
            PasswordBroker::RESET_THROTTLED => $this->handleThrottled($throttle),
            default => $this->failTerminally(new UnexpectedValueException('Unexpected password reset broker status.')),
        };
    }

    /**
     * Nom du broker par défaut et sa fenêtre d'anti-rafale (secondes), avec la
     * même règle que PasswordBrokerManager : « throttle » absent ou null = 0.
     *
     * @return array{0: string, 1: int}
     */
    private function brokerSettings(): array
    {
        $name = config('auth.defaults.passwords');
        $broker = is_string($name) && $name !== '' ? config("auth.passwords.{$name}") : null;
        $throttle = is_array($broker) ? ($broker['throttle'] ?? 0) : null;

        if (is_string($throttle) && ctype_digit($throttle)) {
            $throttle = (int) $throttle;
        }

        if (! is_int($throttle) || $throttle < 0) {
            throw new UnexpectedValueException('Invalid password reset broker configuration.');
        }

        return [$name, $throttle];
    }

    private function handleThrottled(int $throttle): void
    {
        // 1re tentative : une demande récente a déjà son lien (déduplication).
        // attempts() vaut aussi 1 hors contexte de file (aucune remise en file
        // possible sans tâche de file attachée).
        if ($this->attempts() <= 1) {
            return;
        }

        // Nouvelle tentative : le jeton récent peut venir d'une tentative
        // précédente interrompue (lien jamais reçu) ou d'une autre demande ; ce
        // n'est pas une preuve d'orphelin. Priorité à la remise : attendre toute
        // la fenêtre, puis renvoyer un lien (seul le dernier reste valide).
        $maxTries = $this->job?->maxTries();

        if (! is_int($maxTries) || $maxTries < 1 || $throttle < 1) {
            $this->failTerminally(new UnexpectedValueException('Invalid password reset retry configuration.'));

            return;
        }

        if ($this->attempts() >= $maxTries) {
            $this->failTerminally(new RuntimeException('Password reset still throttled on the final attempt.'));

            return;
        }

        $this->release($throttle);
    }

    /**
     * Échec terminal explicite (failed_jobs via le worker). Hors contexte de
     * file, fail() ne ferait rien : l'exception est alors levée pour ne jamais
     * passer pour un succès.
     */
    private function failTerminally(Throwable $exception): void
    {
        if ($this->job === null) {
            throw $exception;
        }

        $this->fail($exception);
    }
}
