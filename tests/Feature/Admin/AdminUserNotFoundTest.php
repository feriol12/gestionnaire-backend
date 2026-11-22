<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\AdminUserController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * F-05 / addenda GOV-05 du 22/11/2026 — le 404 de GET /api/admin/users/{id}
 * porte {message, code}. Appel direct du contrôleur (la pile admin complète,
 * MFA comprise, est couverte par les bancs tests/AdminUsersHttp et
 * tests/AdminSuspension, qui exigent une application neuve par requête).
 */
final class AdminUserNotFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_user_returns_the_generic_message_and_stable_code(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $response = app(AdminUserController::class)->show(Request::create('/api/admin/users/999999'), '999999');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Utilisateur introuvable', 'code' => 'USER_NOT_FOUND'], $response->getData(true));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('existing@example.test', (string) $response->getContent());
        $this->assertStringNotContainsString('999999', (string) $response->getContent());
    }
}
