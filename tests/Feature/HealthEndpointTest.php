<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * LWS-BE-04 — racine et sonde de disponibilité de l'API.
 *
 *  - GET|HEAD / : 404 (aucune ressource à la racine), sans session ni cookie,
 *    sans dépendance à la base de données, sans divulgation.
 *  - GET|HEAD /up : 200 {"status":"up"} en JSON quel que soit Accept, sans
 *    session, cookie ni base de données ; soumis au mode maintenance (503).
 *
 * Aucune base de données : « injoignable » = MySQL local sur le port 1.
 */
class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Comportement de production (APP_DEBUG=false), indépendamment du .env local.
        config(['app.debug' => false]);
    }

    protected function tearDown(): void
    {
        // Ne jamais laisser l'application en maintenance, même après un échec.
        if ($this->app && $this->app->maintenanceMode()->active()) {
            $this->artisan('up');
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------ /up

    public function test_up_returns_the_exact_json_status(): void
    {
        $response = $this->getJson('/up')->assertOk();

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('{"status":"up"}', $response->getContent());
        $this->assertNoCookies($response);
    }

    public function test_up_answers_head_with_an_empty_body(): void
    {
        $response = $this->call('HEAD', '/up')->assertOk();

        $this->assertSame('', $response->getContent());
        $this->assertNoCookies($response);
    }

    public function test_up_stays_json_when_html_is_requested(): void
    {
        $response = $this->get('/up', ['Accept' => 'text/html'])->assertOk();

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('{"status":"up"}', $response->getContent());
        $this->assertNoCookies($response);
    }

    public function test_up_does_not_depend_on_the_session_database(): void
    {
        $this->makeSessionDatabaseUnreachable();

        $response = $this->getJson('/up')->assertOk();

        $this->assertSame('{"status":"up"}', $response->getContent());
        $this->assertNoCookies($response);
    }

    public function test_up_carries_the_global_security_headers(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_up_does_not_bypass_maintenance_mode(): void
    {
        $this->artisan('down')->assertExitCode(0);

        try {
            $this->assertTrue($this->app->maintenanceMode()->active());
            $this->getJson('/up')->assertStatus(503);
            $this->get('/up', ['Accept' => 'text/html'])->assertStatus(503);
        } finally {
            $this->artisan('up')->assertExitCode(0);
        }

        $this->assertFalse($this->app->maintenanceMode()->active());
        $this->assertFileDoesNotExist(storage_path('framework/maintenance.php'));
        $this->getJson('/up')->assertOk();
    }

    // ------------------------------------------------------------------ racine

    public function test_root_is_not_found_and_discloses_nothing(): void
    {
        $response = $this->get('/', ['Accept' => 'text/html'])->assertNotFound();

        $this->assertNoCookies($response);
        foreach (['Laravel v', 'PHP v', 'fonts.bunny.net'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }

        $json = $this->getJson('/')->assertNotFound();
        $this->assertNoCookies($json);
        $this->assertSame(['message'], array_keys($json->json()));
    }

    public function test_root_answers_head_with_not_found(): void
    {
        $response = $this->call('HEAD', '/')->assertNotFound();

        $this->assertSame('', $response->getContent());
        $this->assertNoCookies($response);
    }

    public function test_root_does_not_depend_on_the_session_database(): void
    {
        $this->makeSessionDatabaseUnreachable();

        $this->assertNoCookies($this->get('/', ['Accept' => 'text/html'])->assertNotFound());
        $this->assertNoCookies($this->getJson('/')->assertNotFound());
    }

    // ------------------------------------------------------------------ vues

    public function test_the_views_directory_survives_the_welcome_removal_for_view_cache(): void
    {
        // Sans fichier suivi, resources/views n'existe pas dans un clone et
        // « php artisan view:cache » / « optimize » échouent.
        $this->assertFileExists(resource_path('views/.gitkeep'));
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));

        try {
            $this->artisan('view:cache')->assertExitCode(0);
        } finally {
            $this->artisan('view:clear');
        }
    }

    // ------------------------------------------------------------------ helpers

    private function assertNoCookies(TestResponse $response): void
    {
        $this->assertSame([], $response->headers->getCookies(), 'no Set-Cookie expected');
        $response->assertHeaderMissing('Set-Cookie');
    }

    // Session « database » et MySQL local injoignable (port 1) : toute
    // dépendance à la base ferait échouer la requête (500).
    private function makeSessionDatabaseUnreachable(): void
    {
        config([
            'session.driver' => 'database',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
        ]);
        DB::purge('mysql');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $this->assertThrows(fn () => DB::connection('mysql')->getPdo(), \PDOException::class);
    }
}
