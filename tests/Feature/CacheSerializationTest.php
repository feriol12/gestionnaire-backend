<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
 * SEC-AUTH-02A — cache.serializable_classes = false (Laravel 13) : aucune
 * classe PHP n'est reconstruite à la lecture du cache, tandis que les valeurs
 * scalaires réellement utilisées (compteurs du rate limiter) fonctionnent.
 * Utilise un store "file" temporaire, comme en production (CACHE_DRIVER=file).
 */
class CacheSerializationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/cache-serialization-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    private function fileStore()
    {
        return Cache::build(['driver' => 'file', 'path' => $this->path]);
    }

    public function test_object_deserialization_is_disabled_in_effective_config(): void
    {
        $this->assertFalse(config('cache.serializable_classes'));
    }

    public function test_file_store_keeps_scalars_and_arrays_but_never_rebuilds_objects(): void
    {
        $store = $this->fileStore();

        $store->put('int', 42, 60);
        $store->put('string', 'Beauté & soins', 60);
        $store->put('array', ['a' => 1, 'b' => [true, null]], 60);
        $store->put('object', new \ArrayObject(['x' => 1]), 60);

        $this->assertSame(42, $store->get('int'));
        $this->assertSame('Beauté & soins', $store->get('string'));
        $this->assertSame(['a' => 1, 'b' => [true, null]], $store->get('array'));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $store->get('object'));
    }

    public function test_rate_limiter_still_works_on_a_file_store(): void
    {
        $limiter = new RateLimiter($this->fileStore());

        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('auth-test-key', 60);
        }

        $this->assertSame(5, $limiter->attempts('auth-test-key'));
        $this->assertTrue($limiter->tooManyAttempts('auth-test-key', 5));
        $this->assertGreaterThan(0, $limiter->availableIn('auth-test-key'));
    }
}
