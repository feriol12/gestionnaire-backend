<?php

namespace Tests\Feature;

use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Tries;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/*
 * LWS-BE-06C — ops:queue-worker n'ajoute ni --tries ni --backoff : la
 * politique de nouvelle tentative appartient à chaque tâche (LWS-BE-06A,
 * priorité à la remise). Sans #[Tries], une tâche ne serait tentée qu'une
 * fois (défaut de queue:work).
 *
 * Garde (V2, revue Codex N4) : parcours RÉCURSIF de app/Jobs ; chaque classe
 * concrète implémentant ShouldQueue doit porter #[Tries] (> 1) et la
 * connexion « database », directement ou par héritage d'une classe parente.
 * Les classes abstraites et interfaces sont ignorées délibérément.
 */
class QueueJobsRetryPolicyTest extends TestCase
{
    public function test_every_concrete_queued_job_declares_its_own_retry_policy_and_connection(): void
    {
        $jobs = [];
        $root = app_path('Jobs');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root)) + 1, -4);
            $class = 'App\\Jobs\\'.str_replace('/', '\\', $relative);

            $this->assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class), "{$class} must be autoloadable (file name = class name)");

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $jobs[] = $class;

            $tries = $this->attribute($reflection, Tries::class);
            $this->assertNotNull($tries, "{$class} must declare #[Tries] (or inherit it)");
            $this->assertGreaterThan(1, $tries->tries, "{$class} must allow retries");

            $connection = $this->attribute($reflection, Connection::class);
            $this->assertNotNull($connection, "{$class} must declare #[Connection] (or inherit it)");
            $this->assertSame('database', $connection->connection);
        }

        $this->assertNotEmpty($jobs, 'the guard must actually inspect jobs');
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private function attribute(ReflectionClass $class, string $attribute): ?object
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $found = $current->getAttributes($attribute);

            if ($found !== []) {
                return $found[0]->newInstance();
            }
        }

        return null;
    }
}
