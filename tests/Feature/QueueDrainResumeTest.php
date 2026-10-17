<?php

namespace Tests\Feature;

use App\Queue\SingleWorker\PauseGate;
use App\Queue\SingleWorker\WorkerLock;
use App\Queue\SingleWorker\WorkerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\Jobs\LockProbeJob;
use Tests\TestCase;

/*
 * LWS-BE-06C — ops:queue-drain / ops:queue-resume et garde de pause.
 *
 * Drain : garde publiée (atomique, idempotente) AVANT la sonde du verrou ;
 * sonde non bloquante bornée ; 0 = verrou acquis avec la garde présente
 * (puis rendu immédiatement) ; 75 = DRAIN_TIMEOUT, garde conservée ; 74 =
 * échec de la garde ou du verrou, jamais présenté comme un succès.
 * Reprise : seule opération qui retire la garde ; échec visible (74).
 * L'attente du drain est simulée (Sleep::fake) : aucun délai réel.
 */
class QueueDrainResumeTest extends TestCase
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
            'logging.channels.queue_ops.path' => $this->ops.'/logs/queue-worker.log',
            'queue_worker.max_time' => 30,
            'queue_worker.timeout' => 20,
            'queue_worker.sleep' => 1,
            'queue_worker.memory' => 512,
            'queue_worker.runtime_budget' => 60,
        ]);

        LockProbeJob::$observed = null;
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->ops);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function drain(?string $wait = '5'): int
    {
        return Artisan::call('ops:queue-drain', $wait === null ? [] : ['--wait' => $wait]);
    }

    private function resume(): int
    {
        return Artisan::call('ops:queue-resume');
    }

    private function gate(): PauseGate
    {
        return new PauseGate(config('queue_worker.pause_path'));
    }

    /**
     * @return list<string>
     */
    private function eventNames(): array
    {
        return array_column($this->events(), 'event');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $events = [];

        foreach (glob($this->ops.'/logs/queue-worker-*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $events[] = json_decode($line, true)['context'];
            }
        }

        return $events;
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

    private function holdLock(): WorkerLock
    {
        $lock = new WorkerLock(config('queue_worker.lock_path'));
        $this->assertSame(WorkerLock::ACQUIRED, $lock->tryAcquire());

        return $lock;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        @chmod($dir, 0700);

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

    // ------------------------------------------------------------------ drain

    public function test_drain_publishes_the_gate_then_proves_quiescence_and_releases_the_lock(): void
    {
        $this->assertSame(0, $this->drain());

        $state = $this->gate()->state();
        $this->assertTrue($state['paused']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $state['since']);
        $this->assertSame(['INVOCATION_START', 'PAUSE_SET', 'DRAIN_QUIESCED', 'INVOCATION_END'], $this->eventNames());
        $this->assertSame('drain', $this->event('PAUSE_SET')['context']);

        $probe = new WorkerLock(config('queue_worker.lock_path'));
        $this->assertSame(WorkerLock::ACQUIRED, $probe->tryAcquire(), 'drain must not keep the lock');
        $probe->release();

        $this->assertSame([], glob($this->ops.'/queue/.paused.*.tmp'), 'no temporary file is left behind');
    }

    public function test_drain_times_out_safely_while_a_worker_holds_the_lock(): void
    {
        $holder = $this->holdLock();

        try {
            $this->assertSame(WorkerSettings::EXIT_SKIPPED, $this->drain('3'));
        } finally {
            $holder->release();
        }

        $this->assertTrue($this->gate()->state()['paused'], 'the gate stays published after DRAIN_TIMEOUT');
        $this->assertContains('DRAIN_TIMEOUT', $this->eventNames());
        $this->assertNotContains('DRAIN_QUIESCED', $this->eventNames());
        $this->assertContains('DRAIN_WAITING', $this->eventNames());
        Sleep::assertSleptTimes(3);
    }

    public function test_drain_is_idempotent_and_keeps_the_original_pause_time(): void
    {
        $this->assertSame(0, $this->drain());
        $first = $this->gate()->state()['since'];

        $this->assertSame(0, $this->drain());

        $this->assertSame($first, $this->gate()->state()['since']);
        $this->assertContains('PAUSE_ALREADY_SET', $this->eventNames());
    }

    public function test_drain_with_a_missing_or_invalid_wait_publishes_nothing(): void
    {
        foreach ([null, '0', '-5', 'abc', '1.5'] as $wait) {
            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->drain($wait));
        }

        $this->assertFalse($this->gate()->state()['paused']);
        $this->assertNotContains('PAUSE_SET', $this->eventNames());
    }

    public function test_drain_with_invalid_configuration_publishes_nothing(): void
    {
        config(['queue_worker.lock_path' => 'relative/worker.lock']);

        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->drain());
        $this->assertFalse($this->gate()->state()['paused']);
        $this->assertSame([], $this->eventNames(), 'invalid paths: nothing is written to disk');
    }

    public function test_a_lock_error_during_drain_is_never_success_and_the_queue_stays_paused(): void
    {
        $lockPath = config('queue_worker.lock_path');
        touch($lockPath);
        chmod($lockPath, 0444);

        if (is_writable($lockPath)) {
            $this->markTestSkipped('Running with privileges that ignore file permissions.');
        }

        $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->drain());
        $this->assertTrue($this->gate()->state()['paused'], 'fail-safe direction: paused');
        $this->assertNotContains('DRAIN_QUIESCED', $this->eventNames());
        $this->assertSame('open', $this->event('LOCK_ERROR')['stage']);
    }

    public function test_a_gate_that_cannot_be_published_throws_and_is_never_success(): void
    {
        $gate = new PauseGate($this->ops.'/missing-dir/paused');

        try {
            $gate->set(['set_at' => 'x']);
            $this->fail('publishing into a missing directory must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pause', $e->getMessage());
        }

        // Par la commande : un dossier à la place de la garde est refusé dès
        // la validation (2) ; rien n'est publié, rien ne reprend.
        mkdir($this->ops.'/queue/paused');
        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->drain());
        $this->assertNotContains('DRAIN_QUIESCED', $this->eventNames());
    }

    public function test_a_gate_that_vanishes_before_quiescence_is_proven_fails_with_74(): void
    {
        $holder = $this->holdLock();

        // Pendant l'attente : une reprise intempestive retire la garde, puis le
        // worker rend le verrou. Le drain acquiert le verrou sans garde.
        Sleep::whenFakingSleep(function () use ($holder) {
            @unlink(config('queue_worker.pause_path'));
            $holder->release();
        });

        $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->drain());

        $this->assertNotContains('DRAIN_QUIESCED', $this->eventNames());
        $this->assertStringContainsString('vanished', $this->event('LOCK_ERROR')['error']);

        $probe = new WorkerLock(config('queue_worker.lock_path'));
        $this->assertSame(WorkerLock::ACQUIRED, $probe->tryAcquire(), 'the drain released the lock');
        $probe->release();
    }

    // ------------------------------------------------------------------ workers versus the gate

    public function test_after_a_successful_drain_workers_skip_until_explicit_resume(): void
    {
        LockProbeJob::dispatch();

        $this->assertSame(0, $this->drain());
        $this->assertSame(WorkerSettings::EXIT_SKIPPED, Artisan::call('ops:queue-worker', ['--context' => 'cron']));
        $this->assertSame(WorkerSettings::EXIT_SKIPPED, Artisan::call('ops:queue-worker', ['--context' => 'cron']));
        $this->assertNull(LockProbeJob::$observed);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertSame(0, $this->resume());
        $this->assertSame(0, Artisan::call('ops:queue-worker', ['--context' => 'cron']));
        $this->assertSame(WorkerLock::BUSY, LockProbeJob::$observed);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ------------------------------------------------------------------ resume

    public function test_resume_removes_the_gate(): void
    {
        $this->gate()->set(['set_at' => '2026-10-17T00:00:00.000Z']);

        $this->assertSame(0, $this->resume());

        $this->assertFalse($this->gate()->state()['paused']);
        $this->assertSame(['INVOCATION_START', 'PAUSE_CLEARED', 'INVOCATION_END'], $this->eventNames());
    }

    public function test_resume_without_a_gate_is_harmless(): void
    {
        $this->assertSame(0, $this->resume());
        $this->assertContains('PAUSE_NOT_SET', $this->eventNames());
    }

    public function test_a_failed_removal_is_reported_as_failure_and_the_queue_stays_paused(): void
    {
        $this->gate()->set(['set_at' => '2026-10-17T00:00:00.000Z']);
        $pausePath = config('queue_worker.pause_path');

        // Windows : un fichier en lecture seule ne peut pas être supprimé.
        // Linux : la suppression dépend du dossier.
        PHP_OS_FAMILY === 'Windows' ? chmod($pausePath, 0444) : chmod(dirname($pausePath), 0555);

        try {
            if (PHP_OS_FAMILY !== 'Windows' && is_writable(dirname($pausePath))) {
                $this->markTestSkipped('Running with privileges that ignore directory permissions.');
            }

            $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->resume());
            $this->assertTrue($this->gate()->state()['paused']);
            $this->assertNotContains('PAUSE_CLEARED', $this->eventNames());
            $this->assertSame('pause_gate', $this->event('LOCK_ERROR')['stage']);
        } finally {
            PHP_OS_FAMILY === 'Windows' ? chmod($pausePath, 0666) : chmod(dirname($pausePath), 0700);
        }
    }

    public function test_resume_with_invalid_configuration_changes_nothing(): void
    {
        $this->gate()->set(['set_at' => '2026-10-17T00:00:00.000Z']);
        config(['queue_worker.pause_path' => null]);

        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->resume());

        $this->assertTrue((new PauseGate($this->ops.'/queue/paused'))->state()['paused']);
    }

    // ================================================================== V2 — revue Codex B1

    public function test_b1_resume_never_reports_success_on_an_indeterminate_state(): void
    {
        $this->gate()->set(['set_at' => '2026-10-17T00:00:00.000Z']);
        $this->app->bind(PauseGate::class, fn () => new PauseGate($this->ops.'/vanished-gate/paused'));

        $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->resume());

        $this->assertNotContains('PAUSE_NOT_SET', $this->eventNames(), 'absence was not established');
        $this->assertNotContains('PAUSE_CLEARED', $this->eventNames());
        $this->assertSame('pause_gate', $this->event('LOCK_ERROR')['stage']);
        $this->assertTrue((new PauseGate(config('queue_worker.pause_path')))->state()['paused'], 'the real gate is untouched');
    }

    public function test_b1_drain_never_proves_quiescence_on_an_indeterminate_state(): void
    {
        $this->app->bind(PauseGate::class, fn () => new PauseGate($this->ops.'/vanished-gate/paused'));

        $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $this->drain());

        $this->assertNotContains('DRAIN_QUIESCED', $this->eventNames());
        $this->assertNotContains('PAUSE_SET', $this->eventNames());
        $this->assertSame('pause_gate', $this->event('LOCK_ERROR')['stage']);
    }

    public function test_drain_wait_values_beyond_the_representable_bound_are_rejected(): void
    {
        foreach ([(string) (WorkerSettings::MAX_INTEGER + 1), str_repeat('9', 40)] as $wait) {
            $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $this->drain($wait));
        }

        $this->assertFalse($this->gate()->state()['paused']);
    }
}
