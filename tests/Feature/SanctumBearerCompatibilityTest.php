<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * SEC-AUTH-01C — le flux d'authentification Bearer (Sanctum personal access
 * token) doit rester fonctionnellement identique après Laravel 12 / Sanctum 4.
 * AUTH3-01 — le navigateur n'utilise plus de jeton (session Sanctum SPA, voir
 * SessionAuthenticationTest) et /api/login n'en émet plus ; l'infrastructure
 * des jetons personnels reste disponible pour les clients non navigateur :
 * ces tests vérifient qu'un jeton émis côté serveur authentifie toujours.
 */
class SanctumBearerCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-password-123';

    private function issueToken(User $user): string
    {
        $token = $user->createToken('api-client')->plainTextToken;
        $this->assertMatchesRegularExpression('/^\d+\|\S+$/', $token);

        // Each request must authenticate on its own Bearer header, not on a
        // user cached by the guard from a previous request in this test.
        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_protected_endpoints_refuse_unauthenticated_requests(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->getJson('/api/budgets')->assertUnauthorized();
        $this->getJson('/api/dashboard/categories')->assertUnauthorized();
        $this->getJson('/api/export/pdf')->assertUnauthorized();
    }

    public function test_register_keeps_its_current_contract(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Compat User',
            'email' => 'compat@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        // Current contract: 200 with the created user, no token issued.
        $response->assertOk()
            ->assertJsonPath('email', 'compat@example.test')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('users', ['email' => 'compat@example.test']);
        $this->assertSame(0, User::where('email', 'compat@example.test')->first()->tokens()->count());
    }

    public function test_a_personal_access_token_still_authenticates_protected_routes(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $token = $this->issueToken($user);
        $this->assertSame(1, $user->tokens()->count());

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonMissingPath('password');

        $this->withToken($token)->getJson('/api/expenses')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->withToken($token)->getJson('/api/budgets')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->withToken($token)->getJson('/api/dashboard/categories')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_authenticated_pdf_export_still_returns_a_pdf(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $this->issueToken($user);

        $response = $this->withToken($token)->get('/api/export/pdf');

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('depenses_month.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_logout_revokes_the_current_token_and_it_cannot_be_reused(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $this->issueToken($user);

        $this->withToken($token)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/expenses')->assertUnauthorized();
    }

    public function test_login_no_longer_issues_tokens_to_non_browser_clients(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        // No first-party (stateful) origin: no session, so no login and no token.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * Sanctum 4 reads sanctum.middleware.validate_csrf_token. MONEVA keeps its
     * own CSRF middleware there; since AUTH3-01 it exempts no path, so every
     * stateful (first-party origin) API write is CSRF-protected. Real HTTP
     * behaviour is covered in SessionAuthenticationTest.
     */
    public function test_sanctum_4_csrf_middleware_configuration_protects_the_api(): void
    {
        $this->assertSame(PreventRequestForgery::class, config('sanctum.middleware.validate_csrf_token'));
        $this->assertNull(config('sanctum.middleware.verify_csrf_token'));

        $this->assertSame([], $this->app->make(PreventRequestForgery::class)->getExcludedPaths());
    }
}
