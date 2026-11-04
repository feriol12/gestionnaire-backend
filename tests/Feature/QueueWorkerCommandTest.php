<?php

namespace Tests\Feature;

use App\Console\Commands\QueueWorkerCommand;
use App\Jobs\SendPasswordResetEmailJob;
use App\Models\User;
use App\Queue\SingleWorker\OpsLog;
use App\Queue\SingleWorker\PauseGate;
use App\Queue\SingleWorker\WorkerLock;
use App\Queue\SingleWorker\WorkerSettings;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\WorkerStopReason;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Jobs\LockProbeJob;
use Tests\TestCase;

/*
 * LWS-BE-06C — ops:queue-worker : verrou noyau tenu par le processus de la
 * commande, garde de pause et maintenance évaluées SOUS le verrou, queue:work
 * exécuté dans le même processus, libération après son retour, journal
 * structuré, contrat de sortie.
 *
 * Vraie file « database » de gestionnaire_test et vrai queue:work, sauf là où
 * un double du worker (queue.worker) est nécessaire pour provoquer un
 * statut, une exception ou un événement. Chemins opérationnels : dossier
 * temporaire hors de l'application. Les tests de verrou valident la logique
 * sur la plateforme locale ; la preuve Linux/LWS reste celle de 06B/06D.
 */
class QueueWorkerCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/be06c-'.bin2hex(random_bytes(6));
        mkdir($this->ops.'/queue', 0700, true);
        mkdir($this->ops.'/logs', 0700, true);

        config([
            'queue_worker.ops_root' => $this->ops,
            'queue_worker.public_roots' => $this->ops.'-public',
            'queue_worker.lock_path' => $this->ops.'/queue/worker.lock',
            'queue_worker.pause_path' => $this->ops.'/queue/paused',
            'queue_worker.cron_output_path' => $this->ops.'/logs/cron-output.log',
            'logging.channels.queue_ops.path' => $this->ops.'/logs/queue-worker.log',
            'queue_worker.max_time' => 30,
            'queue_worker.timeout' => 20,
            'queue_worker.sleep' => 1,
            'queue_worker.memory' => 512,
            'queue_worker.runtime_budget' => 60,
        ]);

        LockProbeJob::$observed = null;
        LockProbeJob::$displayName = null;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->ops);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function work(string $context = 'manual'): int
    {
        return Artisan::call('ops:queue-worker', ['--context' => $context]);
    }

    /**
     * Événements du journal queue_ops (champs « context » des lignes JSON).
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $events = [];

        foreach (glob($this->ops.'/logs/queue-worker-*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                $events[] = $record['context'];
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function eventNames(): array
    {
        return array_column($this->events(), 'event');
    }

    private function event(string $name): ?array
    {
        foreach ($this->events() as $event) {
            if ($event['event'] === $name) {
                return $event;
            }
        }

        return null;
    }

    private function logText(): string
    {
        return implode('', array_map('file_get_contents', glob($this->ops.'/logs/queue-worker-*.log') ?: []));
    }

    private function assertLockFree(): void
    {
        $lock = new WorkerLock(config('queue_worker.lock_path'));
        $this->assertSame(WorkerLock::ACQUIRED, $lock->tryAcquire(), 'the worker lock must be free after the invocation');
        $lock->release();
    }

    /**
     * Remplace queue.worker par un double dont daemon() exécute $behavior.
     */
    private function fakeWorker(Closure $behavior): object
    {
        $app = $this->app;

        $worker = new class($app['queue'], $app['events'], $app[ExceptionHandler::class], fn () => $app->isDownForMaintenance(), $behavior) extends Worker
        {
            public ?WorkerOptions $options = null;

            public ?string $connectionName = null;

            public ?string $queueName = null;

            public function __construct($manager, $events, $exceptions, callable $isDown, private Closure $behavior)
            {
                parent::__construct($manager, $events, $exceptions, $isDown);
            }

            public function daemon($connectionName, $queue, WorkerOptions $options)
            {
                $this->connectionName = $connectionName;
                $this->queueName = $queue;
                $this->options = $options;

                return ($this->behavior)($this, $options);
            }

            public function dispatchEvent(object $event): void
            {
                $this->events->dispatch($event);
            }
        };

        $this->app->instance('queue.worker', $worker);
        $this->app->forgetInstance(WorkCommand::class);

        return $worker;
    }

    /**
     * Fichiers (pas les dossiers) sous $dir, récursivement.
     *
     * @return list<string>
     */
    private function filesUnder(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }

        return $files;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            @chmod($path, 0700);
            is_dir($path) && ! is_link($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    // ------------------------------------------------------------------ lock held for the whole worker lifetime

    public function test_jobs_run_inside_the_holder_process_while_the_lock_is_held(): void
    {
        LockProbeJob::dispatch();

        $this->assertSame(0, $this->work());

        $this->assertSame(WorkerLock::BUSY, LockProbeJob::$observed, 'a job running under ops:queue-worker must find the lock held');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(
            ['INVOCATION_START', 'LOCK_ACQUIRED', 'WORKER_START', 'JOB_PROCESSED', 'WORKER_END', 'INVOCATION_END'],
            $this->eventNames()
        );

        $end = $this->event('WORKER_END');
        $this->assertSame(0, $end['worker_status']);
        $this->assertSame('empty', $end['stop_reason']);
        $this->assertSame(1, $end['jobs_processed']);

        $final = $this->event('INVOCATION_END');
        $this->assertSame(0, $final['exit_code']);
        $this->assertTrue($final['lock_released']);
        $this->assertIsInt($final['elapsed_ms']);

        $this->assertLockFree();
    }

    public function test_every_event_of_one_invocation_shares_one_run_id_and_runs_are_distinct(): void
    {
        $this->work('cron');
        $this->work('manual');

        $events = $this->events();
        $runIds = array_values(array_unique(array_column($events, 'run_id')));

        $this->assertCount(2, $runIds);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $runIds[0]);
        $this->assertSame(['cron', 'manual'], array_values(array_unique(array_column($events, 'context'))));

        foreach ($events as $event) {
            $this->assertSame('ops:queue-worker', $event['command']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event['ts']);
            $this->assertSame(getmypid(), $event['pid']);
        }

        $this->assertSame(2, count(array_keys($this->eventNames(), 'INVOCATION_END', true)));
    }

    // ------------------------------------------------------------------ never entering the worker

    public function test_a_busy_lock_skips_without_entering_the_worker(): void
    {
        LockProbeJob::dispatch();
        $holder = new WorkerLock(config('queue_worker.lock_path'));
        $holder->tryAcquire();

        try {
            $this->assertSame(WorkerSettings::EXIT_SKIPPED, $this->work());
        } finally {
            $holder->release();
        }

        $this->assertNull(LockProbeJob::$observed, 'the worker must never be entered');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(['INVOCATION_START', 'LOCK_BUSY', 'INVOCATION_END'], $this->eventNames());
        $this->assertNull($this->event('INVOCATION_END')['lock_released']);
    }

    public function test_the_pause_gate_is_read_only_after_the_lock_is_acquired(): void
    {
        (new PauseGate(config('queue_worker.pause_path')))->set(['set_at' => '2026-10-17T00:00:00.000Z']);
        $holder = new WorkerLock(config('queue_worker.lock_path'));
        $holder->tryAcquire();

        try {
            $this->assertSame(WorkerSettings::EXIT_SKIPPED, $this->work());
        } finally {
            $holder->release();
        }

        $this->assertContains('LOCK_BUSY', $this->eventNames());
        $this->assertNotContains('SKIPPED', $this->eventNames(), 'a paused queue must not be observed before the lock is held');
    }

    public function test_a_paused_queue_skips_under_the_lock_and_releases_it(): void
    {
        LockProbeJob::dispatch();
        (new PauseGate(config('queue_worker.pause_path')))->set(['set_at' => '2026-10-17T00:00:00.000Z']);

        $this->assertSame(WorkerSettings::EXIT_SKIPPED, $this->work());

        $this->assertNull(LockProbeJob::$observed);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(['INVOCATION_START', 'LOCK_ACQUIRED', 'SKIPPED', 'INVOCATION_END'], $this->eventNames());
        $this->assertSame('paused', $this->event('SKIPPED')['reason']);
        $this->assertSame('2026-10-17T00:00:00.000Z', $this->event('SKIPPED')['paused_since']);
        $this->assertTrue($this->event('INVOCATION_END')['lock_released']);
        $this->assertLockFree();
    }

    public function test_maintenance_mode_skips_under_the_lock(): void
    {
        LockProbeJob::dispatch();
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void
            {
            }

            public function deactivate(): void
            {
            }

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });

        $this->assertSame(WorkerSettings::EXIT_SKIPPED, $this->work());

        $this->assertNull(LockProbeJob::$observed);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('maintenance', $this->event('SKIPPED')['reason']);
        $this->assertLockFree();
    }

    public static function invalidConfigurations(): array
    {
        return [
            // Chemins : aucune écriture sur disque (V2, revue Codex B2/B3).
            'lock path missing' => [['queue_worker.lock_path' => null]],
            'lock path empty' => [['queue_worker.lock_path' => '']],
            'lock path relative' => [['queue_worker.lock_path' => 'queue/worker.lock']],
            'lock path with relative segment' => [['queue_worker.lock_path' => '{OPS}/queue/../queue/worker.lock']],
            'lock directory missing' => [['queue_worker.lock_path' => '{OPS}/missing/worker.lock']],
            'lock path inside the application' => [['queue_worker.lock_path' => '{BASE}/storage/worker.lock']],
            'lock path outside the ops root' => [['queue_worker.lock_path' => '{TMP}/worker.lock']],
            'pause path missing' => [['queue_worker.pause_path' => null]],
            'pause path equals lock path' => [['queue_worker.pause_path' => '{OPS}/queue/worker.lock']],
            'log path missing' => [['logging.channels.queue_ops.path' => null]],
            'log file name with glob characters' => [['logging.channels.queue_ops.path' => '{OPS}/logs/queue-*.log']],
            'ops root missing' => [['queue_worker.ops_root' => null]],
            'ops root relative' => [['queue_worker.ops_root' => 'fluxmoneva-ops']],
            'ops root does not exist' => [['queue_worker.ops_root' => '{OPS}/absent']],
            'ops root inside the application' => [['queue_worker.ops_root' => '{BASE}/storage']],
            'public roots missing' => [['queue_worker.public_roots' => null]],
            'public roots blank list' => [['queue_worker.public_roots' => ' , ']],
            'public root relative' => [['queue_worker.public_roots' => 'htdocs']],
            'ops root inside a public root' => [['queue_worker.public_roots' => '{TMP}']],
            'ops root containing a public root' => [['queue_worker.public_roots' => '{OPS}/queue']],
            'lock in the log directory' => [['queue_worker.lock_path' => '{OPS}/logs/worker.lock']],
            'lock named as the daily log of today' => [['queue_worker.lock_path' => '{OPS}/logs/queue-worker-{TODAY}.log']],
            'pause named as an old rotated log' => [['queue_worker.pause_path' => '{OPS}/logs/queue-worker-2026-09-01.log']],
            'cron output in the control directory' => [['queue_worker.cron_output_path' => '{OPS}/queue/cron-output.log']],
            'cron output named as a daily log' => [['queue_worker.cron_output_path' => '{OPS}/logs/queue-worker-2026-01-01.log']],
            'cron output equals the log' => [['queue_worker.cron_output_path' => '{OPS}/logs/queue-worker.log']],
            // Valeurs : chemins valides, l'événement CONFIG_INVALID est journalisé.
            'max_time missing' => [['queue_worker.max_time' => null]],
            'timeout not an integer' => [['queue_worker.timeout' => '20s']],
            'sleep zero' => [['queue_worker.sleep' => 0]],
            'memory negative' => [['queue_worker.memory' => -1]],
            'budget missing' => [['queue_worker.runtime_budget' => null]],
            'timeout not below retry_after' => [['queue_worker.timeout' => 90, 'queue_worker.runtime_budget' => 500]],
            'max_time + timeout above budget' => [['queue_worker.runtime_budget' => 49]],
            'retry_after invalid' => [['queue.connections.database.retry_after' => null]],
            'max_time overflowing digits' => [['queue_worker.max_time' => str_repeat('9', 40)]],
            'sleep above the representable bound' => [['queue_worker.sleep' => WorkerSettings::MAX_INTEGER + 1]],
        ];
    }

    private const PATH_KEYS = [
        'queue_worker.ops_root', 'queue_worker.public_roots', 'queue_worker.lock_path', 'queue_worker.pause_path',
        'queue_worker.cron_output_path', 'logging.channels.queue_ops.path',
    ];

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_fails_closed_before_any_lock_or_worker(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            config([$key => is_string($value) ? str_replace(
                ['{OPS}', '{BASE}', '{TMP}', '{TODAY}'],
                [$this->ops, str_replace('\\', '/', base_path()), dirname($this->ops), gmdate('Y-m-d')],
                $value
            ) : $value]);
        }

        LockProbeJob::dispatch();

        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work());

        $this->assertNull(LockProbeJob::$observed);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertFileDoesNotExist($this->ops.'/queue/worker.lock', 'no lock may be attempted');
        $this->assertFileDoesNotExist($this->ops.'/queue/paused');

        if (array_intersect(array_keys($overrides), self::PATH_KEYS) !== []) {
            // Chemins invalides : aucun fichier créé nulle part (ni journal,
            // ni verrou, ni garde) ; seul le repli STDERR subsiste.
            $this->assertSame([], $this->filesUnder($this->ops), 'an invalid path set must have no filesystem side effect');

            return;
        }

        $this->assertSame(['INVOCATION_START', 'CONFIG_INVALID', 'INVOCATION_END'], $this->eventNames());
        $this->assertNotEmpty($this->event('CONFIG_INVALID')['reason']);
        $this->assertSame(2, $this->event('INVOCATION_END')['exit_code']);
    }

    public function test_an_unknown_context_is_invalid_configuration(): void
    {
        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work('nightly'));
        $this->assertSame('invalid', $this->event('INVOCATION_START')['context']);
        $this->assertFileDoesNotExist($this->ops.'/queue/worker.lock');
    }

    public function test_a_directory_at_the_lock_path_is_invalid_configuration(): void
    {
        mkdir($this->ops.'/queue/worker.lock');

        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work());
        $this->assertTrue(is_dir($this->ops.'/queue/worker.lock'));
        $this->assertSame([], $this->events(), 'invalid paths: nothing is written to disk');
    }

    public function test_a_lock_that_cannot_be_opened_fails_closed_with_74(): void
    {
        LockProbeJob::dispatch();
        $lockPath = config('queue_worker.lock_path');
        touch($lockPath);
        chmod($lockPath, 0444);

        if (is_writable($lockPath)) {
            $this->markTestSkipped('Running with privileges that ignore file permissions.');
        }

        $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->work());

        $this->assertNull(LockProbeJob::$observed);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(['INVOCATION_START', 'LOCK_ERROR', 'INVOCATION_END'], $this->eventNames());
        $this->assertSame('open', $this->event('LOCK_ERROR')['stage']);
    }

    // ------------------------------------------------------------------ worker status propagation

    public function test_a_real_worker_memory_stop_propagates_status_12(): void
    {
        config(['queue_worker.memory' => 1]);

        $this->assertSame(12, $this->work());

        $end = $this->event('WORKER_END');
        $this->assertSame(12, $end['worker_status']);
        $this->assertSame('memory', $end['stop_reason']);
        $this->assertSame(12, $this->event('INVOCATION_END')['exit_code']);
        $this->assertLockFree();
    }

    public function test_a_worker_error_status_propagates_verbatim(): void
    {
        $this->fakeWorker(fn () => Worker::EXIT_ERROR);

        $this->assertSame(1, $this->work());
        $this->assertSame(1, $this->event('WORKER_END')['worker_status']);
        $this->assertLockFree();
    }

    public function test_an_exception_escaping_queue_work_is_a_failure_and_the_lock_is_released(): void
    {
        $this->fakeWorker(fn () => throw new RuntimeException('boom'));

        $this->assertSame(WorkerSettings::EXIT_FAILURE, $this->work());

        $failure = $this->event('WORKER_FAILURE');
        $this->assertSame('queue:work', $failure['stage']);
        $this->assertSame(RuntimeException::class, $failure['exception_class']);
        $this->assertNull($this->event('WORKER_END'));
        $this->assertTrue($this->event('INVOCATION_END')['lock_released']);
        $this->assertStringNotContainsString('boom', $this->logText(), 'exception messages are never logged');
        $this->assertLockFree();
    }

    public function test_the_lock_is_held_while_the_worker_frame_runs(): void
    {
        $seen = null;
        $this->fakeWorker(function () use (&$seen) {
            $probe = new WorkerLock(config('queue_worker.lock_path'));
            $seen = $probe->tryAcquire();
            $probe->release();

            return 0;
        });

        $this->assertSame(0, $this->work());
        $this->assertSame(WorkerLock::BUSY, $seen);
        $this->assertLockFree();
    }

    public function test_queue_work_receives_the_structural_options_without_force_or_tries(): void
    {
        $worker = $this->fakeWorker(fn () => 0);

        $this->work();

        $this->assertSame('database', $worker->connectionName);
        $this->assertSame('default', $worker->queueName);
        $this->assertSame('fluxmoneva-single', $worker->options->name);
        $this->assertTrue($worker->options->stopWhenEmpty);
        $this->assertFalse($worker->options->force, 'never --force: no processing in maintenance mode');
        $this->assertSame(1, (int) $worker->options->maxTries, 'queue:work default; job-level #[Tries] governs');
        $this->assertSame(30, (int) $worker->options->maxTime);
        $this->assertSame(20, (int) $worker->options->timeout);
        $this->assertSame(1, (int) $worker->options->sleep);
        $this->assertSame(512, (int) $worker->options->memory);

        $arguments = WorkerSettings::fromConfig(config(), base_path())->workerArguments();

        foreach (['--force', '--tries', '--backoff', '--once', '--max-jobs'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $arguments);
        }

        $this->assertSame(
            ['connection' => 'database', '--queue' => 'default', '--name' => 'fluxmoneva-single', '--stop-when-empty' => true,
                '--max-time' => 30, '--timeout' => 20, '--sleep' => 1, '--memory' => 512],
            $arguments
        );
    }

    // ------------------------------------------------------------------ timeout invariant (current retry_after)

    public function test_the_timeout_must_stay_below_the_current_retry_after(): void
    {
        config(['queue.connections.database.retry_after' => 25, 'queue_worker.timeout' => 25]);
        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work());
        $this->assertStringContainsString('retry_after', $this->event('CONFIG_INVALID')['reason']);

        config(['queue_worker.timeout' => 24]);
        $settings = WorkerSettings::fromConfig(config(), base_path());
        $this->assertSame(25, $settings->retryAfter, 'the live configuration value is used, not a constant');
    }

    // ------------------------------------------------------------------ abnormal death observability

    public function test_a_timeout_stop_event_is_logged_as_worker_killed_before_anything_else(): void
    {
        // Le vrai délai dépassé tue le processus (SIGKILL de lui-même) : non
        // reproductible dans PHPUnit. On vérifie l'écriture synchrone de
        // WORKER_KILLED à la réception de WorkerStopping(TimedOut).
        $this->fakeWorker(function (object $worker, WorkerOptions $options) {
            $worker->dispatchEvent(new WorkerStopping(status: 1, workerOptions: $options, reason: WorkerStopReason::TimedOut, jobsProcessed: 0));

            return 1;
        });

        $this->work();

        $names = $this->eventNames();
        $this->assertContains('WORKER_KILLED', $names);
        $this->assertLessThan(array_search('WORKER_END', $names, true), array_search('WORKER_KILLED', $names, true));
        $this->assertSame('timed_out', $this->event('WORKER_KILLED')['stop_reason']);
    }

    // ------------------------------------------------------------------ observability safety

    public function test_operational_logs_never_contain_secrets_tokens_or_addresses(): void
    {
        Notification::fake();
        config(['mail.mailers.smtp.password' => 'smtp-secret-sentinel-06c']);
        $user = User::factory()->create(['email' => 'recipient-06c@example.test']);

        SendPasswordResetEmailJob::dispatch($user->id);

        $this->assertSame(0, $this->work());

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $text = $this->logText();
        $this->assertStringContainsString('JOB_PROCESSED', $text);
        $this->assertStringContainsString(SendPasswordResetEmailJob::class, str_replace('\\\\', '\\', $text));

        $secrets = array_filter([
            $token,
            'recipient-06c@example.test',
            'smtp-secret-sentinel-06c',
            (string) config('app.key'),
            (string) config('database.connections.mysql.password'),
        ], fn ($value) => is_string($value) && $value !== '');

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }

        $this->assertStringNotContainsString('payload', $text);
    }

    public function test_a_logging_failure_never_blocks_the_worker_or_the_exclusion(): void
    {
        // Un dossier à la place du fichier du jour : toute écriture échoue
        // (un fichier en lecture seule ne suffit pas : Monolog le rechmode).
        mkdir($this->ops.'/logs/queue-worker-'.gmdate('Y-m-d').'.log');

        LockProbeJob::dispatch();

        $this->assertSame(0, $this->work());
        $this->assertSame(WorkerLock::BUSY, LockProbeJob::$observed, 'the worker still ran under the lock');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame([], array_filter(glob($this->ops.'/logs/queue-worker-*.log') ?: [], 'is_file'), 'nothing could be logged');
        $this->assertLockFree();
    }

    public function test_ops_log_falls_back_to_one_secret_free_line_when_it_cannot_write(): void
    {
        $fallback = fopen('php://memory', 'w+');
        config(['logging.channels.queue_ops.path' => null]);

        $log = OpsLog::make('ops:queue-worker', 'manual', $fallback);
        $log->emit('LOCK_BUSY', ['note' => 'x']);

        rewind($fallback);
        $this->assertSame("queue-ops-log-failure event=LOCK_BUSY run_id={$log->runId()}\n", stream_get_contents($fallback));
    }

    public function test_daily_files_keep_fourteen_days_and_are_named_by_utc_date(): void
    {
        for ($day = 1; $day <= 20; $day++) {
            touch(sprintf('%s/logs/queue-worker-2026-09-%02d.log', $this->ops, $day));
        }

        $log = OpsLog::make('ops:queue-worker', 'manual');
        $log->emit('INVOCATION_START');
        $log->close();

        $files = glob($this->ops.'/logs/queue-worker-*.log');
        $this->assertCount(14, $files, 'retention: 14 daily files');
        $this->assertFileExists($this->ops.'/logs/queue-worker-'.gmdate('Y-m-d').'.log');
        $this->assertFileDoesNotExist($this->ops.'/logs/queue-worker-2026-09-01.log');

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($this->ops.'/logs/queue-worker-'.gmdate('Y-m-d').'.log')), -4));
        }
    }

    public function test_listeners_are_registered_once_per_application(): void
    {
        LockProbeJob::dispatch();
        $this->work();
        LockProbeJob::dispatch();
        $this->work();

        $this->assertSame(2, count(array_keys($this->eventNames(), 'JOB_PROCESSED', true)), 'one JOB_PROCESSED per job, never duplicated');
        $this->assertFalse($this->app->bound('ops.queue-worker.active'), 'the active holder is unbound after queue:work');
        $this->assertInstanceOf(QueueWorkerCommand::class, $this->app->make(QueueWorkerCommand::class));
    }

    // ================================================================== V2 — revue Codex

    private function linkDirectory(string $link, string $target): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Jonction : ne demande pas de privilège élevé.
            $process = new \Symfony\Component\Process\Process(['cmd', '/c', 'mklink', '/J', str_replace('/', '\\', $link), str_replace('/', '\\', $target)]);
            $process->run();

            return $process->isSuccessful() && is_dir($link);
        }

        return @symlink($target, $link);
    }

    private function unlinkDirectory(string $link): void
    {
        PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
    }

    // ------------------------------------------------------------------ B1 : état de pause indéterminé

    public function test_b1_absence_is_only_established_from_a_listable_directory(): void
    {
        $vanished = $this->ops.'/vanished-gate/paused';

        // Ancien défaut : file_exists() renvoie false ici, ce qui valait « pas en pause ».
        $this->assertFalse(file_exists($vanished));

        try {
            (new PauseGate($vanished))->state();
            $this->fail('an unlistable pause directory must not be read as "not paused"');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('indeterminate', $e->getMessage());
        }

        $this->assertSame(['paused' => false, 'since' => null], (new PauseGate($this->ops.'/queue/paused'))->state());
    }

    public function test_b1_an_indeterminate_pause_state_never_enters_the_worker(): void
    {
        LockProbeJob::dispatch();

        // Le dossier de la garde devient inaccessible APRÈS la validation de
        // démarrage (cas cité par la revue) : la garde est résolue sur un
        // dossier disparu au moment de la lecture sous le verrou.
        $this->app->bind(PauseGate::class, fn () => new PauseGate($this->ops.'/vanished-gate/paused'));

        $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->work());

        $this->assertNull(LockProbeJob::$observed, 'queue:work must never be entered');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(['INVOCATION_START', 'LOCK_ACQUIRED', 'LOCK_ERROR', 'INVOCATION_END'], $this->eventNames());
        $this->assertSame('pause_gate', $this->event('LOCK_ERROR')['stage']);
        $this->assertStringContainsString('indeterminate', $this->event('LOCK_ERROR')['error']);
        $this->assertSame(74, $this->event('INVOCATION_END')['exit_code']);
        $this->assertLockFree();
    }

    public function test_b1_a_real_permission_failure_on_the_pause_directory_fails_closed(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX directory permissions: covered on Linux; Windows covered by the vanished-directory case.');
        }

        mkdir($this->ops.'/gate', 0700);
        config(['queue_worker.pause_path' => $this->ops.'/gate/paused']);
        (new PauseGate($this->ops.'/gate/paused'))->set(['set_at' => '2026-10-17T00:00:00.000Z']);
        LockProbeJob::dispatch();
        chmod($this->ops.'/gate', 0000);

        try {
            if (is_readable($this->ops.'/gate')) {
                $this->markTestSkipped('Running with privileges that ignore directory permissions.');
            }

            // Ancien défaut : file_exists() est faux alors que la garde existe.
            $this->assertFalse(file_exists($this->ops.'/gate/paused'));

            $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->work());
            $this->assertNull(LockProbeJob::$observed);
            $this->assertSame(1, DB::table('jobs')->count());
        } finally {
            chmod($this->ops.'/gate', 0700);
        }
    }

    // ------------------------------------------------------------------ B2 : racines publiques

    public function test_b2_operational_paths_in_a_public_root_outside_the_application_are_rejected_without_any_write(): void
    {
        $public = $this->ops.'-public';
        $site = $public.'/api.example.test';
        mkdir($site.'/logs', 0700, true);
        LockProbeJob::dispatch();

        try {
            // Reproduction de la revue : chemins acceptés auparavant car hors
            // de base_path (racine web d'un autre site).
            config([
                'queue_worker.ops_root' => $site,
                'queue_worker.lock_path' => $site.'/worker.lock',
                'queue_worker.pause_path' => $site.'/paused',
                'logging.channels.queue_ops.path' => $site.'/logs/queue-worker.log',
                'queue_worker.cron_output_path' => null,
            ]);

            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work());
            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, Artisan::call('ops:queue-drain', ['--wait' => '1']));
            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, Artisan::call('ops:queue-resume'));
            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, Artisan::call('ops:queue-status'));

            // Même chemins avec la vraie racine privée : hors racine privée.
            config(['queue_worker.ops_root' => $this->ops]);
            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work());

            $this->assertSame([], $this->filesUnder($public), 'nothing may ever be written inside a public root');
            $this->assertNull(LockProbeJob::$observed);
            $this->assertSame(1, DB::table('jobs')->count());
        } finally {
            $this->removeTree($public);
        }
    }

    public function test_b2_a_public_root_named_through_an_alias_is_resolved_and_rejected(): void
    {
        $real = $this->ops.'-public';
        $alias = $this->ops.'-alias';
        mkdir($real.'/site/logs', 0700, true);

        if (! $this->linkDirectory($alias, $real)) {
            $this->removeTree($real);
            $this->markTestSkipped('Directory link could not be created on this host.');
        }

        try {
            $this->assertNotSame(str_replace('\\', '/', realpath($alias)), $alias, 'the alias resolves elsewhere');

            config([
                'queue_worker.public_roots' => $alias,
                'queue_worker.ops_root' => $real.'/site',
                'queue_worker.lock_path' => $real.'/site/worker.lock',
                'queue_worker.pause_path' => $real.'/site/paused',
                'logging.channels.queue_ops.path' => $real.'/site/logs/queue-worker.log',
                'queue_worker.cron_output_path' => null,
            ]);

            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work());
            $this->assertSame([], $this->filesUnder($real));
        } finally {
            $this->unlinkDirectory($alias);
            $this->removeTree($real);
        }
    }

    // ------------------------------------------------------------------ B3 : espaces de noms contrôle / observabilité

    public function test_b3_daily_rotation_and_retention_never_touch_the_lock_or_the_pause_flag(): void
    {
        (new PauseGate(config('queue_worker.pause_path')))->set(['set_at' => '2026-10-17T00:00:00.000Z']);
        touch(config('queue_worker.lock_path'));

        for ($day = 1; $day <= 20; $day++) {
            touch(sprintf('%s/logs/queue-worker-2026-09-%02d.log', $this->ops, $day));
        }

        $log = OpsLog::make('ops:queue-worker', 'manual');
        $log->emit('INVOCATION_START');
        $log->close();

        $this->assertFileExists(config('queue_worker.lock_path'));
        $this->assertFileExists(config('queue_worker.pause_path'));
        $this->assertCount(14, glob($this->ops.'/logs/queue-worker-*.log'));
    }

    public function test_b3_a_lock_named_like_a_daily_log_is_rejected_before_any_log_write(): void
    {
        $collision = $this->ops.'/logs/queue-worker-'.gmdate('Y-m-d').'.log';
        config(['queue_worker.lock_path' => $collision]);

        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->work());

        $this->assertFileDoesNotExist($collision, 'neither the logger nor the lock touched the colliding name');
        $this->assertSame([], $this->filesUnder($this->ops));
    }

    // ------------------------------------------------------------------ N5 : périmètre du journal

    public function test_ops_log_drops_fields_outside_its_whitelist(): void
    {
        $log = OpsLog::make('ops:queue-worker', 'manual');
        $log->emit('LOCK_BUSY', ['payload' => 'secret-payload-06c', 'reason' => 'kept']);
        $log->close();

        $this->assertStringNotContainsString('secret-payload-06c', $this->logText());
        $this->assertSame('kept', $this->event('LOCK_BUSY')['reason']);
        $this->assertArrayNotHasKey('payload', $this->event('LOCK_BUSY'));
    }

    public function test_a_custom_job_display_name_is_never_logged(): void
    {
        LockProbeJob::$displayName = 'recipient-06c@example.test';
        LockProbeJob::dispatch();

        $this->assertSame(0, $this->work());

        $this->assertSame('unrecognized', $this->event('JOB_PROCESSED')['job']);
        $this->assertStringNotContainsString('recipient-06c@example.test', $this->logText());
    }

    public function test_a_pause_flag_content_that_is_not_a_timestamp_is_never_echoed(): void
    {
        file_put_contents(config('queue_worker.pause_path'), '{"set_at":"free text 06c"}');

        $this->assertSame(WorkerSettings::EXIT_SKIPPED, $this->work());

        $this->assertNull($this->event('SKIPPED')['paused_since']);
        $this->assertStringNotContainsString('free text 06c', $this->logText());
    }

    public function test_invocation_start_carries_the_validated_lock_path(): void
    {
        $this->work();

        $this->assertSame(config('queue_worker.lock_path'), $this->event('INVOCATION_START')['lock_path']);
    }
}
