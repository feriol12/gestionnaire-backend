<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * SEC-AUTH-02A / SEC-003 — un budget appartenant à un autre utilisateur doit
 * être indiscernable d'un budget inexistant (même statut, même corps JSON),
 * et ne jamais pouvoir être lu, modifié ou supprimé.
 */
class BudgetOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private Budget $foreignBudget;

    private int $missingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->foreignBudget = $this->budgetFor($userB, 300000);
        $this->missingId = $this->foreignBudget->id + 1000;

        Sanctum::actingAs($this->userA);
    }

    private function budgetFor(User $user, int $amount): Budget
    {
        return Budget::create([
            'user_id' => $user->id,
            'month' => now()->startOfMonth()->toDateString(),
            'amount' => $amount,
        ]);
    }

    private function updatePayload(): array
    {
        return ['month' => now()->startOfMonth()->toDateString(), 'amount' => 1];
    }

    private function assertForeignIndistinguishableFromMissing(string $method, array $payload = []): void
    {
        $foreign = $this->json($method, '/api/budgets/'.$this->foreignBudget->id, $payload);
        $missing = $this->json($method, '/api/budgets/'.$this->missingId, $payload);

        $foreign->assertNotFound()->assertExactJson(['message' => 'Budget non trouvé']);
        $missing->assertNotFound()->assertExactJson(['message' => 'Budget non trouvé']);

        $this->assertSame($missing->getContent(), $foreign->getContent(), "$method body differs");
        $this->assertSame($missing->headers->get('Content-Type'), $foreign->headers->get('Content-Type'));
    }

    public function test_show_foreign_and_missing_budget_are_indistinguishable(): void
    {
        $this->assertForeignIndistinguishableFromMissing('GET');
    }

    public function test_update_foreign_and_missing_budget_are_indistinguishable_and_foreign_is_untouched(): void
    {
        $this->assertForeignIndistinguishableFromMissing('PUT', $this->updatePayload());
        $this->assertForeignIndistinguishableFromMissing('PATCH', $this->updatePayload());

        $this->assertDatabaseHas('budgets', [
            'id' => $this->foreignBudget->id,
            'user_id' => $this->foreignBudget->user_id,
            'amount' => '300000.00',
        ]);
    }

    public function test_delete_foreign_and_missing_budget_are_indistinguishable_and_foreign_survives(): void
    {
        $this->assertForeignIndistinguishableFromMissing('DELETE');

        $this->assertDatabaseHas('budgets', ['id' => $this->foreignBudget->id]);
    }

    public function test_owner_keeps_the_existing_show_update_and_delete_behavior(): void
    {
        $own = $this->budgetFor($this->userA, 150000);

        $this->getJson('/api/budgets/'.$own->id)
            ->assertOk()
            ->assertJsonPath('data.id', $own->id)
            ->assertJsonPath('data.amount', 150000);

        $this->putJson('/api/budgets/'.$own->id, ['month' => now()->startOfMonth()->toDateString(), 'amount' => 175000])
            ->assertOk()
            ->assertJsonPath('message', 'Budget modifié avec succès')
            ->assertJsonPath('budget.amount', 175000);

        $this->deleteJson('/api/budgets/'.$own->id)
            ->assertOk()
            ->assertJsonPath('message', 'Budget supprimé avec succès');

        $this->assertDatabaseMissing('budgets', ['id' => $own->id]);
        $this->assertDatabaseHas('budgets', ['id' => $this->foreignBudget->id]);
    }
}
