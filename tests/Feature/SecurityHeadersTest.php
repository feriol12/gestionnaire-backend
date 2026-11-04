<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * SEC-AUTH-02A — en-têtes de sécurité du backend (API JSON / PDF).
 * Seuls X-Content-Type-Options et Referrer-Policy sont ajoutés ; ni CSP ni
 * HSTS au niveau applicatif, et CORS doit rester inchangé.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'https://monevaa.netlify.app';

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_json_responses_carry_the_security_headers(): void
    {
        $this->assertSecurityHeaders($this->getJson('/api/user')->assertUnauthorized());

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/expenses')->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSecurityHeaders($response);

        $this->assertSecurityHeaders($this->getJson('/api/budgets/999999')->assertNotFound());
    }

    public function test_pdf_downloads_carry_the_security_headers(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->get('/api/export/pdf')->assertOk();

        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSecurityHeaders($response);
    }

    public function test_health_endpoint_carries_the_security_headers(): void
    {
        // LWS-BE-04 : la racine répond 404 ; /up est le point public stable en 200.
        $this->assertSecurityHeaders($this->get('/up')->assertOk());
    }

    public function test_cors_preflight_and_actual_requests_are_unchanged(): void
    {
        config(['cors.allowed_origins' => [self::FRONTEND]]);

        $preflight = $this->call('OPTIONS', '/api/expenses', [], [], [], [
            'HTTP_ORIGIN' => self::FRONTEND,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $preflight->assertNoContent();
        $preflight->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);
        $preflight->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertSecurityHeaders($preflight);

        Sanctum::actingAs(User::factory()->create());

        $actual = $this->withHeaders(['Origin' => self::FRONTEND])->getJson('/api/expenses')->assertOk();
        $actual->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);
        $this->assertSecurityHeaders($actual);

        // Single allowed origin: the configured origin is always returned, a
        // foreign origin is never reflected (pre-existing CORS behavior).
        $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/expenses')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);
    }
}
