<?php

namespace Tests\Unit;

use App\Queue\SingleWorker\WorkerLock;
use LogicException;
use PHPUnit\Framework\TestCase;

/*
 * LWS-BE-06C — WorkerLock (flock(2) via PHP), sans Laravel.
 *
 * QUALIFICATION DE PLATEFORME : ces tests valident la logique de la classe
 * sur la plateforme qui les exécute (Windows en local : LockFileEx). Ils ne
 * remplacent PAS la preuve Linux/LWS (LWS-BE-06B ; répétition LWS-BE-06D).
 * Deux descripteurs ouverts séparément dans le même processus sont deux
 * détenteurs distincts pour le noyau (Linux : description de fichier ouverte
 * distincte ; Windows : handle distinct).
 */
class WorkerLockTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/be06c-lock-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->path = $this->dir.'/worker.lock';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }

            is_link($entry) || is_file($entry) ? @unlink($entry) : @rmdir($entry);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_a_free_lock_is_acquired_and_created_without_content(): void
    {
        $lock = new WorkerLock($this->path);

        $this->assertSame(WorkerLock::ACQUIRED, $lock->tryAcquire());
        $this->assertTrue($lock->isHeld());
        $this->assertFileExists($this->path);
        $this->assertTrue($lock->release());
        $this->assertFalse($lock->isHeld());
        // Lu après libération : sous Windows, le verrou est obligatoire.
        $this->assertSame('', file_get_contents($this->path));
    }

    public function test_a_second_independent_holder_gets_busy_never_acquired(): void
    {
        $first = new WorkerLock($this->path);
        $second = new WorkerLock($this->path);

        $this->assertSame(WorkerLock::ACQUIRED, $first->tryAcquire());
        $this->assertSame(WorkerLock::BUSY, $second->tryAcquire(), 'contention must be classified as BUSY (wouldBlock)');
        $this->assertFalse($second->isHeld());

        $first->release();
    }

    public function test_the_lock_is_acquirable_again_after_release(): void
    {
        $first = new WorkerLock($this->path);
        $second = new WorkerLock($this->path);

        $first->tryAcquire();
        $first->release();

        $this->assertSame(WorkerLock::ACQUIRED, $second->tryAcquire());
        $second->release();
    }

    public function test_the_lock_file_is_never_truncated(): void
    {
        file_put_contents($this->path, 'x');
        $lock = new WorkerLock($this->path);

        $lock->tryAcquire();
        $lock->release();

        $this->assertSame('x', file_get_contents($this->path));
    }

    public function test_a_missing_directory_is_an_error_not_busy(): void
    {
        $lock = new WorkerLock($this->dir.'/missing/worker.lock');

        $this->assertSame(WorkerLock::ERROR, $lock->tryAcquire());
        $this->assertSame('open', $lock->errorStage());
        $this->assertNotNull($lock->error());
        $this->assertFalse($lock->isHeld());
    }

    public function test_a_directory_in_place_of_the_lock_file_is_an_error(): void
    {
        mkdir($this->path);
        $lock = new WorkerLock($this->path);

        $this->assertSame(WorkerLock::ERROR, $lock->tryAcquire());
        $this->assertSame('open', $lock->errorStage());
    }

    public function test_a_symbolic_link_lock_path_is_refused(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symbolic links require elevated privileges on Windows; covered on Linux.');
        }

        touch($this->dir.'/target');
        symlink($this->dir.'/target', $this->path);
        $lock = new WorkerLock($this->path);

        $this->assertSame(WorkerLock::ERROR, $lock->tryAcquire());
        $this->assertSame('lock path is a symbolic link', $lock->error());
    }

    public function test_acquiring_twice_on_the_same_instance_is_a_programming_error(): void
    {
        $lock = new WorkerLock($this->path);
        $lock->tryAcquire();

        try {
            $this->expectException(LogicException::class);
            $lock->tryAcquire();
        } finally {
            $lock->release();
        }
    }

    public function test_release_without_holding_is_harmless(): void
    {
        $this->assertTrue((new WorkerLock($this->path))->release());
    }
}
