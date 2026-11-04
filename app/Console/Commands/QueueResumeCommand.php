<?php

namespace App\Console\Commands;

use App\Queue\SingleWorker\OpsLog;
use App\Queue\SingleWorker\PauseGate;
use App\Queue\SingleWorker\WorkerSettings;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/*
 * LWS-BE-06C — reprise explicite après un déploiement.
 *
 * Seule opération qui retire la garde de pause : rien d'autre (exception,
 * migration échouée, drain expiré, configuration invalide) ne reprend la
 * file automatiquement. Un échec de retrait est journalisé et sort en 74 :
 * il n'est jamais présenté comme un succès. Une garde absente n'est pas une
 * erreur (0, PAUSE_NOT_SET).
 */
class QueueResumeCommand extends Command
{
    protected $signature = 'ops:queue-resume';

    protected $description = 'Retire la garde de pause du worker de file unique (fin de déploiement)';

    public function handle(): int
    {
        $started = hrtime(true);
        $log = OpsLog::make('ops:queue-resume', 'resume');
        $log->emit('INVOCATION_START');

        $exit = $this->resume($log);

        $log->emit('INVOCATION_END', ['exit_code' => $exit, 'elapsed_ms' => intdiv(hrtime(true) - $started, 1_000_000)]);
        $log->close();

        return $exit;
    }

    private function resume(OpsLog $log): int
    {
        try {
            $paths = WorkerSettings::paths($this->laravel['config'], $this->laravel->basePath());
        } catch (InvalidArgumentException $e) {
            $log->emit('CONFIG_INVALID', ['reason' => $e->getMessage()]);
            $this->error('Invalid configuration: '.$e->getMessage());

            return WorkerSettings::EXIT_INVALID_CONFIG;
        }

        try {
            // État indéterminé (revue Codex B1) : exception => 74, jamais
            // « PAUSE_NOT_SET ».
            $removed = $this->laravel->make(PauseGate::class, ['path' => $paths['pause']])->clear();
        } catch (RuntimeException $e) {
            $log->emit('LOCK_ERROR', ['stage' => 'pause_gate', 'error' => $e->getMessage()]);
            $this->error('The pause flag could NOT be removed: '.$e->getMessage().'. The queue stays paused.');

            return WorkerSettings::EXIT_LOCK_ERROR;
        }

        $log->emit($removed ? 'PAUSE_CLEARED' : 'PAUSE_NOT_SET');
        $this->info($removed ? 'Pause flag removed: the next worker invocation will process the queue.' : 'No pause flag was set.');

        return WorkerSettings::EXIT_SUCCESS;
    }
}
