<?php

namespace Tests\Support\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Connection;

/*
 * LWS-BE-06C (tests, I2) — tâche lente qui consigne son intervalle
 * d'exécution (identifiant de tâche, pid, entrée, sortie) dans un fichier :
 * deux workers simultanés ne doivent jamais produire d'intervalles qui se
 * chevauchent, et chaque identifiant doit apparaître exactement une fois.
 */
#[Connection('database')]
class IntervalJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $file, public int $seconds, public string $id)
    {
    }

    public function handle(): void
    {
        $enter = microtime(true);
        sleep($this->seconds);
        $leave = microtime(true);

        file_put_contents($this->file, sprintf("%s %d %.6F %.6F\n", $this->id, getmypid(), $enter, $leave), FILE_APPEND | LOCK_EX);
    }
}
