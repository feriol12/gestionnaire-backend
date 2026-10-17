<?php

namespace Tests\Feature;

use App\Queue\SingleWorker\OpsLog;
use App\Queue\SingleWorker\PauseGate;
use App\Queue\SingleWorker\WorkerLock;
use App\Queue\SingleWorker\WorkerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Jobs\LockProbeJob;
use Tests\TestCase;

/*
 * LWS-BE-06C — ops:queue-status : garde, maintenance, sonde du verrou,
 * compteurs de file, nom de la base (jamais d'identifiant), fichier de
 * capture Cron (alerte de taille, revue N-2) et détection PASSIVE des
 * exécutions sans fin enregistrée (revue N-3).
 */
class QueueStatusCommandTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        foreach (glob($this->ops.'/{queue,logs}/{,.}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && @unlink($file);
        }

        @rmdir($this->ops.'/queue');
        @rmdir($this->ops.'/logs');
        @rmdir($this->ops);

        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function queueStatus(array $options = []): array
    {
        $exit = Artisan::call('ops:queue-status', ['--json' => true] + $options);
        $output = Artisan::output();

        return [$exit, json_decode($output, true), $output];
    }

    public function test_a_quiet_installation_is_reported_as_free_and_not_paused(): void
    {
        LockProbeJob::dispatch();

        [$exit, $report] = $this->queueStatus();

        $this->assertSame(0, $exit);
        $this->assertSame('valid', $report['config']);
        $this->assertFalse($report['paused']);
        $this->assertFalse($report['maintenance']);
        $this->assertStringStartsWith('free', $report['lock']);
        $this->assertSame('gestionnaire_test', $report['database']);
        $this->assertSame(1, $report['jobs_pending']);
        $this->assertSame(0, $report['jobs_reserved']);
        $this->assertSame(0, $report['failed_jobs']);
        $this->assertNotNull($report['oldest_pending_available_at']);
        $this->assertSame(0, $report['unmatched_worker_runs']['count']);
        $this->assertSame('testing', $report['app_env']);
    }

    public function test_paused_and_busy_states_are_reported(): void
    {
        (new PauseGate(config('queue_worker.pause_path')))->set(['set_at' => '2026-10-17T00:00:00.000Z']);
        $holder = new WorkerLock(config('queue_worker.lock_path'));
        $holder->tryAcquire();

        try {
            [$exit, $report] = $this->queueStatus();
        } finally {
            $holder->release();
        }

        $this->assertSame(0, $exit);
        $this->assertTrue($report['paused']);
        $this->assertSame('2026-10-17T00:00:00.000Z', $report['paused_since']);
        $this->assertStringStartsWith('busy', $report['lock']);
    }

    public function test_no_lock_probe_leaves_the_lock_untouched(): void
    {
        [$exit, $report] = $this->queueStatus(['--no-lock-probe' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('not probed', $report['lock']);
        $this->assertFileDoesNotExist(config('queue_worker.lock_path'), 'the lock file was not even opened');
    }

    public function test_a_start_without_an_end_is_listed_as_an_unmatched_run(): void
    {
        // Exécution complète (appariée), puis une exécution interrompue
        // (processus tué : START et WORKER_START, sans fin).
        Artisan::call('ops:queue-worker');

        $killed = OpsLog::make('ops:queue-worker', 'cron');
        $killed->emit('INVOCATION_START');
        $killed->emit('LOCK_ACQUIRED');
        $killed->emit('WORKER_START');
        $killed->close();

        [, $report] = $this->queueStatus();

        $this->assertSame(1, $report['unmatched_worker_runs']['count']);
        $this->assertSame($killed->runId(), $report['unmatched_worker_runs']['latest'][0]['run_id']);
        $this->assertSame('WORKER_START', $report['unmatched_worker_runs']['latest'][0]['last_event']);
        $this->assertStringContainsString('Not proof', $report['unmatched_worker_runs']['note']);
    }

    public function test_cron_output_size_is_reported_and_alerts_above_the_threshold(): void
    {
        [, $report] = $this->queueStatus();
        $this->assertSame(0, $report['cron_output_bytes']);
        $this->assertNull($report['cron_output_alert']);

        file_put_contents(config('queue_worker.cron_output_path'), "PHP Fatal error: x\n");
        [, $report] = $this->queueStatus();
        $this->assertStringContainsString('non-empty', $report['cron_output_alert']);

        file_put_contents(config('queue_worker.cron_output_path'), str_repeat('x', 1_048_577));
        [, $report] = $this->queueStatus();
        $this->assertStringStartsWith('ALERT', $report['cron_output_alert']);

        config(['queue_worker.cron_output_path' => null]);
        [, $report] = $this->queueStatus();
        $this->assertSame('not configured', $report['cron_output']);
    }

    public function test_status_never_prints_credentials(): void
    {
        [, , $output] = $this->queueStatus();

        foreach ([(string) config('app.key'), (string) config('database.connections.mysql.password'), (string) config('database.connections.mysql.username')] as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $output);
            }
        }
    }

    public function test_invalid_configuration_is_reported_with_exit_2(): void
    {
        config(['queue_worker.lock_path' => null]);

        [$exit, $report] = $this->queueStatus();

        $this->assertSame(WorkerSettings::EXIT_INVALID_CONFIG, $exit);
        $this->assertStringStartsWith('invalid', $report['config']);
    }

    public function test_human_readable_output_is_available(): void
    {
        $this->assertSame(0, Artisan::call('ops:queue-status'));
        $this->assertStringContainsString('lock_path', Artisan::output());
    }

    // ================================================================== V2 — revue Codex

    public function test_b1_status_reports_an_indeterminate_pause_state_with_74(): void
    {
        $this->app->bind(PauseGate::class, fn () => new PauseGate($this->ops.'/vanished-gate/paused'));

        [$exit, $report] = $this->queueStatus();

        $this->assertSame(WorkerSettings::EXIT_LOCK_ERROR, $exit);
        $this->assertStringStartsWith('unknown', $report['paused']);
    }

    public function test_missing_log_files_are_reported_as_degraded_observability(): void
    {
        [, $report] = $this->queueStatus();

        $this->assertSame(['missing'], array_values(array_unique($report['unmatched_worker_runs']['files'])));
        $this->assertStringContainsString('degraded observability', $report['unmatched_worker_runs']['note']);
    }

    public function test_worker_numeric_configuration_is_reported_separately(): void
    {
        config(['queue_worker.max_time' => null]);

        [$exit, $report] = $this->queueStatus();

        $this->assertSame(0, $exit);
        $this->assertSame('valid', $report['config']);
        $this->assertStringStartsWith('invalid', $report['worker_config']);
    }
}
