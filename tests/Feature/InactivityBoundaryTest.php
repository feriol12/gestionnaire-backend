<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
 * Contrat de compatibilité du middleware activity.check (CheckLastActivity) :
 * figé sous Laravel 10 / Carbon 2 avant la montée de version (SEC-AUTH-01C),
 * puis rejoué tel quel sous Carbon 3.
 *
 * Règle métier observée : une session reste active tant que l'inactivité
 * n'excède pas 1440 minutes (24 h) ; au-delà, le jeton courant est révoqué et
 * la requête reçoit un 401.
 *
 * Utilise un vrai jeton Bearer (et non Sanctum::actingAs) car le middleware
 * appelle currentAccessToken()->delete().
 */
class InactivityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userInactiveFor(int $minutes): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $user->forceFill(['last_activity' => now()->subMinutes($minutes)])->save();

        return [$user, $token];
    }

    public function test_1439_minutes_of_inactivity_keeps_the_session_active(): void
    {
        [$user, $token] = $this->userInactiveFor(1439);

        $this->withToken($token)->getJson('/api/expenses')->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(self::NOW, $user->fresh()->getRawOriginal('last_activity'));
    }

    public function test_exactly_1440_minutes_of_inactivity_keeps_the_session_active(): void
    {
        [$user, $token] = $this->userInactiveFor(1440);

        $this->withToken($token)->getJson('/api/expenses')->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(self::NOW, $user->fresh()->getRawOriginal('last_activity'));
    }

    public function test_1441_minutes_of_inactivity_revokes_the_current_token(): void
    {
        [$user, $token] = $this->userInactiveFor(1441);

        $response = $this->withToken($token)->getJson('/api/expenses');

        $response->assertStatus(401);
        $this->assertStringStartsWith('Session expir', (string) $response->json('message'));
        $this->assertSame(0, $user->tokens()->count());

        // The revoked token must not authenticate a later request.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/expenses')->assertStatus(401);
    }

    public function test_expiry_only_revokes_the_token_used_for_the_request(): void
    {
        [$user, $token] = $this->userInactiveFor(1441);
        $user->createToken('other-device');

        $this->withToken($token)->getJson('/api/expenses')->assertStatus(401);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('other-device', $user->tokens()->first()->name);
    }
}
