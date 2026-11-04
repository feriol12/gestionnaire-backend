<?php

namespace App\Console\Commands;

use App\Queue\SingleWorker\OpsLog;
use App\Queue\SingleWorker\PauseGate;
use App\Queue\SingleWorker\WorkerLock;
use App\Queue\SingleWorker\WorkerSettings;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleased;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerStopReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/*
 * LWS-BE-06C — seul point d'entrée approuvé du worker de file (Cron et manuel).
 *
 * Un seul processus PHP, du début à la fin :
 *   validation de la configuration (sinon code 2, aucun verrou)
 *   -> ouverture du verrou stable + flock(LOCK_EX|LOCK_NB) (occupé : 75 ;
 *      erreur : 74 ; aucun worker dans les deux cas)
 *   -> SOUS le verrou : garde de pause, puis mode maintenance (75)
 *   -> queue:work exécuté de façon SYNCHRONE dans CE processus
 *      (callSilently -> Application::find() -> run() : ni fork, ni
 *      proc_open, ni Process ; vérifié sur Laravel 13.33.0)
 *   -> libération (LOCK_UN + fermeture) dans un finally, après le retour de
 *      queue:work uniquement.
 *
 * Le worker est donc un appel imbriqué du processus qui tient le verrou : il
 * ne peut pas lui survivre (durée du verrou >= durée du worker). Si le
 * processus meurt (délai dépassé : Worker::kill() s'envoie SIGKILL ; SIGKILL
 * externe ; erreur fatale), aucune ligne finale ne peut être écrite et ce
 * n'est PAS un retour de callSilently() : le noyau libère le verrou, et la
 * trace est un INVOCATION_START sans INVOCATION_END (voir ops:queue-status).
 *
 * Codes de sortie : statut du worker tel quel (0, 12 mémoire, 1 erreur), 75
 * ignoré volontairement, 74 erreur du verrou ou de la garde, 2 configuration
 * invalide, 1 échec inattendu. Le panneau LWS ne les affiche pas : le
 * journal queue_ops fait foi.
 */
class QueueWorkerCommand extends Command
{
    protected $signature = 'ops:queue-worker
                            {--context=manual : Étiquette de corrélation (cron | manual)}';

    protected $description = 'Worker de file unique (verrou noyau, garde de pause, queue:work dans ce processus)';

    private const CONTEXTS = ['cron', 'manual'];

    // Écouteurs d'événements du worker : enregistrés une seule fois par
    // conteneur ; ils s'adressent à la commande active (liée pendant
    // queue:work uniquement).
    private const LISTENERS = 'ops.queue-worker.listeners';

    private const ACTIVE = 'ops.queue-worker.active';

    private ?OpsLog $log = null;

    /** @var array{status: mixed, reason: ?string, jobs: mixed}|null */
    private ?array $stop = null;

    public function handle(): int
    {
        $started = hrtime(true);
        $context = (string) $this->option('context');

        $this->log = OpsLog::make('ops:queue-worker', in_array($context, self::CONTEXTS, true) ? $context : 'invalid');
        $this->log->emit('INVOCATION_START', ['lock_path' => $this->log->lockPath()]);

        $lockReleased = null;

        try {
            $exit = $this->invoke($context, $lockReleased);
        } catch (Throwable $e) {
            // Échec inattendu du détenteur ; le verrou a déjà été rendu
            // (finally de invoke()) s'il avait été acquis.
            $this->log->emit('WORKER_FAILURE', ['stage' => 'holder', 'exception_class' => $e::class]);
            report($e);
            $exit = WorkerSettings::EXIT_FAILURE;
        }

        $this->log->emit('INVOCATION_END', [
            'exit_code' => $exit,
            'elapsed_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'lock_released' => $lockReleased,
        ]);
        $this->log->close();

        return $exit;
    }

    /**
     * Point d'entrée des écouteurs (événements du worker pendant queue:work).
     * Ne lève jamais d'exception.
     */
    public function recordWorkerEvent(object $event): void
    {
        try {
            $log = $this->log;

            if ($log === null) {
                return;
            }

            match (true) {
                $event instanceof JobProcessed => $log->emit('JOB_PROCESSED', $this->jobFields($event->job)),
                $event instanceof JobReleased => $log->emit('JOB_RELEASED', $this->jobFields($event->job)),
                $event instanceof JobReleasedAfterException => $log->emit('JOB_RELEASED', $this->jobFields($event->job) + [
                    'exception_class' => is_object($event->exception) ? $event->exception::class : null,
                ]),
                $event instanceof JobFailed => $log->emit('JOB_FAILED', $this->jobFields($event->job) + [
                    'exception_class' => $event->exception::class,
                ]),
                $event instanceof JobTimedOut => $log->emit('JOB_TIMED_OUT', $this->jobFields($event->job)),
                $event instanceof WorkerStopping => $this->recordStopping($event, $log),
                default => null,
            };
        } catch (Throwable) {
            // Observabilité dégradée, sans effet sur l'exclusion.
        }
    }

    private function invoke(string $context, ?bool &$lockReleased): int
    {
        if (! in_array($context, self::CONTEXTS, true)) {
            $this->log->emit('CONFIG_INVALID', ['reason' => '--context must be cron or manual.']);

            return WorkerSettings::EXIT_INVALID_CONFIG;
        }

        try {
            $settings = WorkerSettings::fromConfig($this->laravel['config'], $this->laravel->basePath());
        } catch (InvalidArgumentException $e) {
            $this->log->emit('CONFIG_INVALID', ['reason' => $e->getMessage()]);

            return WorkerSettings::EXIT_INVALID_CONFIG;
        }

        $lock = new WorkerLock($settings->lockPath);
        $result = $lock->tryAcquire();

        if ($result === WorkerLock::BUSY) {
            $this->log->emit('LOCK_BUSY');

            return WorkerSettings::EXIT_SKIPPED;
        }

        if ($result !== WorkerLock::ACQUIRED) {
            $this->log->emit('LOCK_ERROR', ['stage' => $lock->errorStage(), 'error' => $lock->error()]);

            return WorkerSettings::EXIT_LOCK_ERROR;
        }

        $this->log->emit('LOCK_ACQUIRED');

        try {
            return $this->runUnderLock($settings);
        } finally {
            $lockReleased = $lock->release();

            if (! $lockReleased) {
                $this->log->emit('LOCK_ERROR', ['stage' => 'unlock', 'error' => 'LOCK_UN failed; descriptor closed']);
            }
        }
    }

    private function runUnderLock(WorkerSettings $settings): int
    {
        // Garde évaluée SOUS le verrou (ordre exigé par le protocole de drain).
        // État indéterminé (revue Codex B1) : exception => 74, aucun worker.
        try {
            $pause = $this->laravel->make(PauseGate::class, ['path' => $settings->pausePath])->state();
        } catch (RuntimeException $e) {
            $this->log->emit('LOCK_ERROR', ['stage' => 'pause_gate', 'error' => $e->getMessage()]);

            return WorkerSettings::EXIT_LOCK_ERROR;
        }

        if ($pause['paused']) {
            $this->log->emit('SKIPPED', ['reason' => 'paused', 'paused_since' => $pause['since']]);

            return WorkerSettings::EXIT_SKIPPED;
        }

        // Pré-contrôle explicite ; queue:work (sans --force) refuse aussi de
        // traiter si la maintenance commence pendant son exécution.
        if ($this->laravel->isDownForMaintenance()) {
            $this->log->emit('SKIPPED', ['reason' => 'maintenance']);

            return WorkerSettings::EXIT_SKIPPED;
        }

        $this->registerWorkerListeners();
        $this->stop = null;

        $this->log->emit('WORKER_START', ['options' => [
            'connection' => WorkerSettings::CONNECTION,
            'queue' => WorkerSettings::QUEUE,
            'max_time' => $settings->maxTime,
            'timeout' => $settings->timeout,
            'sleep' => $settings->sleep,
            'memory' => $settings->memory,
        ]]);

        $this->laravel->instance(self::ACTIVE, $this);

        try {
            $status = $this->callSilently('queue:work', $settings->workerArguments());
        } catch (Throwable $e) {
            $this->log->emit('WORKER_FAILURE', ['stage' => 'queue:work', 'exception_class' => $e::class]);
            report($e);

            return WorkerSettings::EXIT_FAILURE;
        } finally {
            $this->laravel->forgetInstance(self::ACTIVE);
        }

        $this->log->emit('WORKER_END', [
            'worker_status' => $status,
            'stop_reason' => $this->stop['reason'] ?? null,
            'jobs_processed' => $this->stop['jobs'] ?? null,
        ]);

        return (int) $status;
    }

    private function registerWorkerListeners(): void
    {
        if ($this->laravel->bound(self::LISTENERS)) {
            return;
        }

        $this->laravel->instance(self::LISTENERS, true);

        $app = $this->laravel;

        $app['events']->listen(
            [JobProcessed::class, JobReleased::class, JobReleasedAfterException::class, JobFailed::class, JobTimedOut::class, WorkerStopping::class],
            function (object $event) use ($app): void {
                if ($app->bound(self::ACTIVE)) {
                    $app->make(self::ACTIVE)->recordWorkerEvent($event);
                }
            }
        );
    }

    private function recordStopping(WorkerStopping $event, OpsLog $log): void
    {
        $reason = $event->reason instanceof WorkerStopReason ? $event->reason->value : (is_string($event->reason) ? $event->reason : null);

        $this->stop = ['status' => $event->status, 'reason' => $reason, 'jobs' => $event->jobsProcessed];

        // Délai dépassé : Worker::kill() va envoyer SIGKILL à CE processus
        // juste après cet événement (synchrone). Dernière ligne possible ;
        // aucun INVOCATION_END ne suivra.
        if ($reason === WorkerStopReason::TimedOut->value) {
            $log->emit('WORKER_KILLED', [
                'worker_status' => $event->status,
                'stop_reason' => $reason,
                'jobs_processed' => $event->jobsProcessed,
            ]);
        }
    }

    /**
     * @return array{job: ?string, job_id: ?string, attempts: ?int}
     */
    private function jobFields(mixed $job): array
    {
        if (! $job instanceof Job) {
            return ['job' => null, 'job_id' => null, 'attempts' => null];
        }

        // Nom d'affichage issu de la charge utile : seulement s'il a la forme
        // d'un nom de classe (un displayName personnalisé pourrait contenir
        // des données applicatives).
        $name = $job->resolveName();

        return [
            'job' => is_string($name) && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $name) ? $name : 'unrecognized',
            'job_id' => (string) $job->getJobId(),
            'attempts' => $job->attempts(),
        ];
    }
}
