<?php

namespace App\Console\Commands;

use App\Queue\SingleWorker\OpsLog;
use App\Queue\SingleWorker\PauseGate;
use App\Queue\SingleWorker\WorkerLock;
use App\Queue\SingleWorker\WorkerSettings;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;

/*
 * LWS-BE-06C — mise au repos (quiescence) avant un déploiement.
 *
 *  1. Publie la garde de pause (atomique, idempotente : une garde existante
 *     est conservée avec son set_at d'origine).
 *  2. Sonde le MÊME verrou noyau que le worker, sans jamais attendre
 *     (LOCK_EX|LOCK_NB), avec une pause d'une seconde entre deux sondes, au
 *     plus --wait pauses (borne en nombre de sondes, pas une échéance
 *     d'horloge stricte : journal et système de fichiers s'y ajoutent).
 *  3. Dès qu'il l'acquiert : vérifie que la garde est toujours là, journalise
 *     DRAIN_QUIESCED, rend le verrou immédiatement et sort en 0.
 *
 * Code 0 signifie exactement : à l'instant de l'acquisition, aucun worker ne
 * tenait le verrou, et la garde était publiée ; tout worker qui acquerra le
 * verrou ensuite la lira (il la lit sous le verrou) et n'entrera pas dans
 * queue:work, jusqu'à ops:queue-resume. Ce n'est PAS un verrou conservé
 * pendant le déploiement.
 *
 * Échecs, tous sûrs (la garde reste en place, rien n'est repris) :
 *   75 DRAIN_TIMEOUT : un worker tient encore le verrou ; relancer le drain,
 *      ne PAS poursuivre le déploiement ;
 *   74 garde impossible à publier/lire, garde disparue avant la preuve, ou
 *      erreur du verrou ;
 *    2 configuration ou --wait invalide.
 * Aucun ps, /proc, PID ni test d'existence du fichier de verrou.
 */
class QueueDrainCommand extends Command
{
    protected $signature = 'ops:queue-drain
                            {--wait= : Nombre maximal de pauses de 1 s entre les sondes (obligatoire, 1 à 86400)}';

    protected $description = 'Met la file en pause et prouve qu\'aucun worker ne tient le verrou (avant déploiement)';

    private const NOTICE_EVERY = 5;

    public function handle(): int
    {
        $started = hrtime(true);
        $log = OpsLog::make('ops:queue-drain', 'drain');
        $log->emit('INVOCATION_START');

        $exit = $this->drain($log);

        $log->emit('INVOCATION_END', ['exit_code' => $exit, 'elapsed_ms' => intdiv(hrtime(true) - $started, 1_000_000)]);
        $log->close();

        return $exit;
    }

    private function drain(OpsLog $log): int
    {
        try {
            $wait = WorkerSettings::positiveInteger('--wait', $this->option('wait'));
        } catch (InvalidArgumentException $e) {
            $log->emit('CONFIG_INVALID', ['reason' => $e->getMessage()]);
            $this->error($e->getMessage());

            return WorkerSettings::EXIT_INVALID_CONFIG;
        }

        try {
            $paths = WorkerSettings::paths($this->laravel['config'], $this->laravel->basePath());
        } catch (InvalidArgumentException $e) {
            $log->emit('CONFIG_INVALID', ['reason' => $e->getMessage()]);
            $this->error('Invalid configuration: '.$e->getMessage());

            return WorkerSettings::EXIT_INVALID_CONFIG;
        }

        $gate = $this->laravel->make(PauseGate::class, ['path' => $paths['pause']]);

        try {
            $published = $gate->set([
                'set_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'context' => 'drain',
                'run_id' => $log->runId(),
            ]);
            $since = $gate->state()['since'];
        } catch (RuntimeException $e) {
            $log->emit('LOCK_ERROR', ['stage' => 'pause_gate', 'error' => $e->getMessage()]);
            $this->error('Pause flag could not be published: '.$e->getMessage());

            return WorkerSettings::EXIT_LOCK_ERROR;
        }

        $log->emit($published ? 'PAUSE_SET' : 'PAUSE_ALREADY_SET', ['paused_since' => $since]);
        $this->line($published ? 'Pause flag published.' : 'Pause flag already present (kept).');

        $lock = new WorkerLock($paths['lock']);
        $started = hrtime(true);

        for ($attempt = 0; ; $attempt++) {
            $result = $lock->tryAcquire();
            $waitedMs = intdiv(hrtime(true) - $started, 1_000_000);

            if ($result === WorkerLock::ACQUIRED) {
                return $this->proveQuiescence($log, $gate, $lock, $waitedMs);
            }

            if ($result !== WorkerLock::BUSY) {
                $log->emit('LOCK_ERROR', ['stage' => $lock->errorStage(), 'error' => $lock->error()]);
                $this->error('Lock error: '.$lock->error());

                return WorkerSettings::EXIT_LOCK_ERROR;
            }

            if ($attempt >= $wait) {
                $log->emit('DRAIN_TIMEOUT', ['waited_ms' => $waitedMs]);
                $this->error('DRAIN_TIMEOUT: a worker still holds the lock. The queue stays paused. Re-run ops:queue-drain; do NOT continue the deployment.');

                return WorkerSettings::EXIT_SKIPPED;
            }

            if ($attempt % self::NOTICE_EVERY === 0) {
                $log->emit('DRAIN_WAITING', ['waited_ms' => $waitedMs]);
                $this->line("Worker active, waiting... ({$attempt}s / {$wait}s)");
            }

            Sleep::for(1)->second();
        }
    }

    private function proveQuiescence(OpsLog $log, PauseGate $gate, WorkerLock $lock, int $waitedMs): int
    {
        try {
            $paused = $gate->state()['paused'];
        } catch (RuntimeException $e) {
            $lock->release();
            $log->emit('LOCK_ERROR', ['stage' => 'pause_gate', 'error' => $e->getMessage()]);
            $this->error('Pause flag unreadable: '.$e->getMessage());

            return WorkerSettings::EXIT_LOCK_ERROR;
        }

        // Rendu immédiat : la garde (pas le verrou) porte la quiescence.
        $released = $lock->release();

        if (! $paused) {
            $log->emit('LOCK_ERROR', ['stage' => 'pause_gate', 'error' => 'pause flag vanished before quiescence was proven']);
            $this->error('The pause flag disappeared before quiescence was proven. Quiescence NOT established.');

            return WorkerSettings::EXIT_LOCK_ERROR;
        }

        if (! $released) {
            $log->emit('LOCK_ERROR', ['stage' => 'unlock', 'error' => 'LOCK_UN failed; descriptor closed']);
        }

        $log->emit('DRAIN_QUIESCED', ['waited_ms' => $waitedMs]);
        $this->info('QUIESCED: no worker holds the lock and the queue is paused. Deployment may proceed; finish with ops:queue-resume.');

        return WorkerSettings::EXIT_SUCCESS;
    }
}
