<?php

namespace Tests\Feature;

use App\Queue\SingleWorker\WorkerLock;
use App\Queue\SingleWorker\WorkerSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\Jobs\IntervalJob;
use Tests\TestCase;

/*
 * LWS-BE-06C — exclusion entre VRAIS processus PHP distincts.
 *
 * QUALIFICATION DE PLATEFORME : ces tests prouvent l'exclusion sur la
 * plateforme qui les exécute (Windows en local : LockFileEx ; Linux en
 * CI/serveur : flock(2)). Un succès local sous Windows ne remplace PAS la
 * preuve Linux/LWS (LWS-BE-06B, répétition LWS-BE-06D). Symfony Process est
 * utilisé UNIQUEMENT par ces tests pour lancer des processus concurrents.
 *
 * I1 (suite par défaut) : un processus PHP nu tient le verrou ; la commande
 * ops:queue-worker du processus de test doit s'abstenir (75) sans base.
 *
 * I2 (groupe process-integration, exclu par défaut) : deux processus
 * « artisan ops:queue-worker » réels, environnement explicite pointant vers
 * gestionnaire_test (contrôle préalable par ops:queue-status --json avant
 * tout worker) ; les intervalles d'exécution des tâches ne se chevauchent
 * jamais. Base de test recréée (migrate:fresh) et données validées hors
 * transaction pour être visibles des processus enfants.
 */
class QueueWorkerProcessIntegrationTest extends TestCase
{
    private string $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/be06c-'.bin2hex(random_bytes(6));
        mkdir($this->ops.'/queue', 0700, true);
        mkdir($this->ops.'/logs', 0700, true);

        config($this->opsConfig());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->ops.'/{queue,logs}/{,.}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && @unlink($file);
        }

        @unlink($this->ops.'/intervals.txt');
        @rmdir($this->ops.'/queue');
        @rmdir($this->ops.'/logs');
        @rmdir($this->ops);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function opsConfig(): array
    {
        return [
            'queue_worker.ops_root' => $this->ops,
            'queue_worker.public_roots' => $this->ops.'-public',
            'queue_worker.lock_path' => $this->ops.'/queue/worker.lock',
            'queue_worker.pause_path' => $this->ops.'/queue/paused',
            'logging.channels.queue_ops.path' => $this->ops.'/logs/queue-worker.log',
            'queue_worker.max_time' => 30,
            'queue_worker.timeout' => 20,
            'queue_worker.sleep' => 1,
            'queue_worker.memory' => 512,
            'queue_worker.runtime_budget' => 60,
        ];
    }

    private function waitForLine(Process $process, string $expected, float $seconds = 15.0): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (str_contains($process->getOutput(), $expected)) {
                return;
            }

            if (! $process->isRunning()) {
                break;
            }

            usleep(50_000);
        }

        $this->fail("child process did not report {$expected}: ".$process->getOutput().$process->getErrorOutput());
    }

    // ------------------------------------------------------------------ I1

    public function test_a_lock_held_by_another_php_process_makes_the_worker_skip(): void
    {
        $child = new Process([
            PHP_BINARY,
            base_path('tests/Support/process/hold_lock.php'),
            base_path('vendor/autoload.php'),
            config('queue_worker.lock_path'),
            '4',
        ]);
        $child->start();

        try {
            $this->waitForLine($child, 'ACQUIRED');

            $probe = new WorkerLock(config('queue_worker.lock_path'));
            $this->assertSame(WorkerLock::BUSY, $probe->tryAcquire(), 'another process holds the lock');

            $this->assertSame(WorkerSettings::EXIT_SKIPPED, Artisan::call('ops:queue-worker', ['--context' => 'manual']));
        } finally {
            $child->wait();
        }

        $this->assertSame(0, $child->getExitCode());

        $probe = new WorkerLock(config('queue_worker.lock_path'));
        $this->assertSame(WorkerLock::ACQUIRED, $probe->tryAcquire(), 'released when the other process ends');
        $probe->release();
    }

    // ------------------------------------------------------------------ I2

    #[Group('process-integration')]
    public function test_two_real_worker_processes_never_run_jobs_concurrently(): void
    {
        $this->artisan('migrate:fresh')->assertExitCode(0);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'gestionnaire_test',
            'DATABASE_URL' => '',
            'CACHE_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'QUEUE_WORKER_OPS_ROOT' => $this->ops,
            'QUEUE_WORKER_PUBLIC_ROOTS' => $this->ops.'-public',
            'QUEUE_WORKER_LOCK_PATH' => $this->ops.'/queue/worker.lock',
            'QUEUE_WORKER_PAUSE_PATH' => $this->ops.'/queue/paused',
            'QUEUE_WORKER_LOG_PATH' => $this->ops.'/logs/queue-worker.log',
            'QUEUE_WORKER_MAX_TIME' => '30',
            'QUEUE_WORKER_TIMEOUT' => '20',
            'QUEUE_WORKER_SLEEP' => '1',
            'QUEUE_WORKER_MEMORY' => '512',
            'QUEUE_WORKER_RUNTIME_BUDGET' => '60',
        ];

        // Contrôle préalable : les enfants pointent bien vers gestionnaire_test.
        $preflight = new Process([PHP_BINARY, base_path('artisan'), 'ops:queue-status', '--json', '--no-lock-probe'], base_path(), $env);
        $preflight->mustRun();
        $report = json_decode($preflight->getOutput(), true);
        $this->assertSame('gestionnaire_test', $report['database'] ?? null, 'children must target gestionnaire_test');
        $this->assertSame('testing', $report['app_env'] ?? null);

        $intervals = $this->ops.'/intervals.txt';
        $workers = [];

        try {
            $ids = ['job-a', 'job-b', 'job-c'];

            foreach ($ids as $id) {
                IntervalJob::dispatch($intervals, 2, $id);
            }

            foreach (['a', 'b'] as $name) {
                $workers[$name] = new Process([PHP_BINARY, base_path('artisan'), 'ops:queue-worker', '--context=manual'], base_path(), $env, null, 120);
                $workers[$name]->start();
            }

            $codes = [];

            foreach ($workers as $name => $worker) {
                $codes[$name] = $worker->wait();
            }

            foreach ($codes as $code) {
                $this->assertContains($code, [0, WorkerSettings::EXIT_SKIPPED]);
            }

            $this->assertContains(0, $codes);
            $this->assertSame(0, DB::table('jobs')->count(), 'every job was processed');
            $this->assertSame(0, DB::table('failed_jobs')->count());

            // Ligne : « id pid entrée sortie ».
            $rows = array_map(fn (string $line) => explode(' ', $line), file($intervals, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

            $seen = array_count_values(array_column($rows, 0));
            ksort($seen);
            $this->assertSame(['job-a' => 1, 'job-b' => 1, 'job-c' => 1], $seen, 'each job ran exactly once (by identity)');

            usort($rows, fn (array $a, array $b) => (float) $a[2] <=> (float) $b[2]);

            for ($i = 1; $i < count($rows); $i++) {
                $this->assertGreaterThanOrEqual((float) $rows[$i - 1][3], (float) $rows[$i][2], 'job execution intervals must never overlap');
            }
        } finally {
            // Revue Codex N3 : arrêter et attendre les enfants AVANT de
            // nettoyer les lignes partagées de la base de test.
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(10);
                }
            }

            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();
        }
    }
}
