<?php

namespace App\Console\Commands;

use App\Queue\SingleWorker\PauseGate;
use App\Queue\SingleWorker\WorkerLock;
use App\Queue\SingleWorker\WorkerSettings;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/*
 * LWS-BE-06C — diagnostic du worker de file unique (lecture seule, hormis la
 * sonde du verrou).
 *
 * Rapporte : garde de pause, maintenance, sonde du verrou (acquisition non
 * bloquante immédiatement rendue ; un lancement Cron simultané peut alors
 * être ignoré une fois, sans conséquence ; --no-lock-probe la désactive),
 * compteurs de la file database, NOM de la base (jamais hôte, utilisateur ni
 * mot de passe), journaux, taille du fichier de capture Cron (alerte au-delà
 * d'un seuil), et exécutions du worker sans fin enregistrée.
 *
 * Exécution sans fin enregistrée (INVOCATION_START sans INVOCATION_END dans
 * les journaux d'aujourd'hui et d'hier) : détection PASSIVE d'un arrêt non
 * propre possible (SIGKILL, délai dépassé, erreur fatale, mémoire) — ou d'une
 * exécution encore en cours. Ce n'est pas une preuve : à recouper avec la
 * sonde du verrou, WORKER_KILLED et le fichier de capture Cron.
 *
 * Codes : 0 ; 2 configuration invalide ; 74 erreur de la sonde ou de la garde.
 */
class QueueStatusCommand extends Command
{
    protected $signature = 'ops:queue-status
                            {--no-lock-probe : Ne pas sonder le verrou}
                            {--json : Sortie JSON}';

    protected $description = 'État du worker de file unique (garde, verrou, file, journaux)';

    // Seuil d'alerte du fichier de capture Cron (normalement vide).
    private const CRON_OUTPUT_WARN_BYTES = 1_048_576;

    private const UNMATCHED_SHOWN = 5;

    public function handle(): int
    {
        $report = ['app_env' => $this->laravel->environment()];
        $exit = WorkerSettings::EXIT_SUCCESS;

        try {
            $paths = WorkerSettings::paths($this->laravel['config'], $this->laravel->basePath());
            $report['config'] = 'valid';
        } catch (InvalidArgumentException $e) {
            $report['config'] = 'invalid: '.$e->getMessage();

            return $this->render($report, WorkerSettings::EXIT_INVALID_CONFIG);
        }

        $report['lock_path'] = $paths['lock'];
        $report['pause_path'] = $paths['pause'];

        try {
            WorkerSettings::fromConfig($this->laravel['config'], $this->laravel->basePath());
            $report['worker_config'] = 'valid';
        } catch (InvalidArgumentException $e) {
            $report['worker_config'] = 'invalid: '.$e->getMessage();
        }

        try {
            $pause = $this->laravel->make(PauseGate::class, ['path' => $paths['pause']])->state();
            $report['paused'] = $pause['paused'];
            $report['paused_since'] = $pause['since'];
        } catch (RuntimeException $e) {
            $report['paused'] = 'unknown: '.$e->getMessage();
            $exit = WorkerSettings::EXIT_LOCK_ERROR;
        }

        $report['maintenance'] = $this->laravel->isDownForMaintenance();

        if ($this->option('no-lock-probe')) {
            $report['lock'] = 'not probed';
        } else {
            $lock = new WorkerLock($paths['lock']);
            $result = $lock->tryAcquire();
            $lock->release();

            $report['lock'] = match ($result) {
                WorkerLock::ACQUIRED => 'free (no worker held the lock at probe time)',
                WorkerLock::BUSY => 'busy (a worker or another ops command holds it)',
                default => 'error: '.$lock->error(),
            };

            if ($result === WorkerLock::ERROR) {
                $exit = WorkerSettings::EXIT_LOCK_ERROR;
            }
        }

        $report += $this->queueCounts();

        $logFiles = $this->dailyLogFiles($paths['log']);
        $report['ops_log_today'] = $logFiles[0];
        $report['unmatched_worker_runs'] = $this->unmatchedRuns($logFiles);

        $report += $this->cronOutput($paths['cron_output']);

        return $this->render($report, $exit);
    }

    /**
     * @return array<string, mixed>
     */
    private function queueCounts(): array
    {
        try {
            $connection = DB::connection();
            $jobs = $connection->table('jobs')->where('queue', WorkerSettings::QUEUE);
            $oldest = (clone $jobs)->whereNull('reserved_at')->min('available_at');

            return [
                'database' => $connection->getDatabaseName(),
                'jobs_pending' => (clone $jobs)->whereNull('reserved_at')->count(),
                'jobs_reserved' => (clone $jobs)->whereNotNull('reserved_at')->count(),
                'oldest_pending_available_at' => $oldest === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $oldest),
                'failed_jobs' => $connection->table('failed_jobs')->count(),
            ];
        } catch (Throwable $e) {
            return ['database' => 'error: '.$e::class];
        }
    }

    /**
     * Fichiers quotidiens du canal queue_ops (aujourd'hui, hier ; UTC).
     *
     * @return list<string>
     */
    private function dailyLogFiles(string $logPath): array
    {
        $info = pathinfo($logPath);
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';
        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return array_map(
            fn (DateTimeImmutable $day) => $info['dirname'].'/'.$info['filename'].'-'.$day->format('Y-m-d').$extension,
            [$today, $today->modify('-1 day')]
        );
    }

    /**
     * @param  list<string>  $files
     * @return array{count: int, latest: list<array<string, mixed>>, files: array<string, string>, note: string}
     */
    private function unmatchedRuns(array $files): array
    {
        $runs = [];

        $states = [];

        foreach (array_reverse($files) as $file) {
            clearstatcache(true, $file);

            if (! is_file($file)) {
                $states[basename($file)] = 'missing';

                continue;
            }

            $handle = is_readable($file) ? @fopen($file, 'r') : false;

            if ($handle === false) {
                $states[basename($file)] = 'unreadable';

                continue;
            }

            $states[basename($file)] = 'read';

            // Lecture ligne à ligne : pas de chargement complet en mémoire.
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                $fields = is_array($record) ? ($record['context'] ?? null) : null;

                if (! is_array($fields) || ($fields['command'] ?? null) !== 'ops:queue-worker' || ! is_string($fields['run_id'] ?? null)) {
                    continue;
                }

                $id = $fields['run_id'];
                $runs[$id] ??= ['run_id' => $id, 'started_at' => null, 'last_event' => null, 'ended' => false];
                $runs[$id]['last_event'] = $fields['event'] ?? null;

                if (($fields['event'] ?? null) === 'INVOCATION_START') {
                    $runs[$id]['started_at'] = $fields['ts'] ?? null;
                }

                if (($fields['event'] ?? null) === 'INVOCATION_END') {
                    $runs[$id]['ended'] = true;
                }
            }

            fclose($handle);
        }

        $unmatched = array_values(array_filter($runs, fn (array $run) => ! $run['ended'] && $run['started_at'] !== null));

        return [
            'count' => count($unmatched),
            'latest' => array_map(
                fn (array $run) => ['run_id' => $run['run_id'], 'started_at' => $run['started_at'], 'last_event' => $run['last_event']],
                array_slice($unmatched, -self::UNMATCHED_SHOWN)
            ),
            'files' => $states,
            'note' => 'A start without an end means a possibly unclean termination (SIGKILL, timeout kill, fatal error, OOM) OR a run still in progress. Not proof: cross-check with the lock probe, WORKER_KILLED and the Cron output file. Missing or unreadable log files mean degraded observability: a count of 0 is then not evidence of health.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cronOutput(?string $path): array
    {
        // Chemin déjà validé avec les autres (WorkerSettings::paths()).
        if ($path === null) {
            return ['cron_output' => 'not configured'];
        }

        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : 0;

        return [
            'cron_output' => $path,
            'cron_output_bytes' => $size,
            'cron_output_alert' => $size > self::CRON_OUTPUT_WARN_BYTES
                ? 'ALERT: exceeds '.self::CRON_OUTPUT_WARN_BYTES.' bytes (repeated pre-Laravel failures?). Inspect, then truncate per the runbook.'
                : ($size > 0 ? 'non-empty: inspect (normally empty)' : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report, int $exit): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $exit;
        }

        foreach ($report as $key => $value) {
            $this->line(str_pad($key, 30).' '.(is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_SLASHES)));
        }

        return $exit;
    }
}
