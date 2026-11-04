<?php

namespace App\Queue\SingleWorker;

use LogicException;

/*
 * LWS-BE-06C — verrou noyau du worker unique (flock(2) via PHP).
 *
 * Seule l'acquisition non bloquante LOCK_EX|LOCK_NB est utilisée (prouvée sur
 * LWS en LWS-BE-06B). Le descripteur appartient à cette instance, donc au
 * processus PHP qui l'a ouvert : le verrou dure tant que ce descripteur est
 * ouvert ou que le processus vit ; le noyau le libère à la mort du processus.
 *
 *  - Ouverture « c » : création si absent, JAMAIS de troncature ; le fichier
 *    n'est jamais écrit ni supprimé (le supprimer pendant qu'il est tenu
 *    séparerait le domaine d'exclusion en deux inodes).
 *  - Occupé (wouldBlock = 1) => BUSY ; tout autre échec => ERROR (l'appelant
 *    échoue fermé : aucun worker).
 *  - Pas de fichier PID, pas d'âge, pas de nettoyage de verrou « périmé » :
 *    un verrou consultatif du noyau n'existe que tant que son détenteur vit.
 *
 * Aucune dépendance Laravel (utilisable par un processus PHP nu, voir
 * tests/Support/process/hold_lock.php).
 */
final class WorkerLock
{
    public const ACQUIRED = 'acquired';

    public const BUSY = 'busy';

    public const ERROR = 'error';

    /** @var resource|null */
    private $handle = null;

    private ?string $errorStage = null;

    private ?string $error = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Tente d'acquérir le verrou sans attendre : ACQUIRED, BUSY ou ERROR.
     */
    public function tryAcquire(): string
    {
        if ($this->handle !== null) {
            throw new LogicException('The worker lock is already held by this instance.');
        }

        $this->errorStage = null;
        $this->error = null;

        clearstatcache(true, $this->path);

        if (is_link($this->path)) {
            return $this->fail('open', 'lock path is a symbolic link');
        }

        error_clear_last();
        $handle = @fopen($this->path, 'c');

        if ($handle === false) {
            return $this->fail('open', error_get_last()['message'] ?? 'fopen failed');
        }

        $wouldBlock = 0;

        error_clear_last();

        if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            $this->handle = $handle;

            return self::ACQUIRED;
        }

        $error = error_get_last()['message'] ?? 'flock failed';
        fclose($handle);

        return $wouldBlock ? self::BUSY : $this->fail('lock', $error);
    }

    /**
     * Libère explicitement (LOCK_UN) puis ferme le descripteur. La fermeture
     * libère aussi le verrou : elle a lieu même si LOCK_UN échoue. Renvoie
     * false si LOCK_UN a échoué.
     */
    public function release(): bool
    {
        if ($this->handle === null) {
            return true;
        }

        $unlocked = flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;

        return $unlocked;
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    public function errorStage(): ?string
    {
        return $this->errorStage;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    private function fail(string $stage, string $error): string
    {
        $this->errorStage = $stage;
        $this->error = $error;

        return self::ERROR;
    }
}
