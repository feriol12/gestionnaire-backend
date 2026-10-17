<?php

namespace Tests\Support\Jobs;

use App\Queue\SingleWorker\WorkerLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Connection;

/*
 * LWS-BE-06C (tests) — tâche exécutée PAR le worker : elle tente le verrou
 * du worker avec un descripteur indépendant et note le résultat. « busy »
 * prouve que le verrou est tenu pendant l'exécution des tâches.
 */
#[Connection('database')]
class LockProbeJob implements ShouldQueue
{
    use Queueable;

    public static ?string $observed = null;

    // Nom d'affichage personnalisé (V2, revue Codex N5) : jamais journalisé
    // s'il n'a pas la forme d'un nom de classe.
    public static ?string $displayName = null;

    public function displayName(): string
    {
        return self::$displayName ?? static::class;
    }

    public function handle(): void
    {
        $lock = new WorkerLock((string) config('queue_worker.lock_path'));
        $result = $lock->tryAcquire();
        $lock->release();

        self::$observed = $result;
    }
}
