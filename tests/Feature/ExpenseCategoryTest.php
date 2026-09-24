<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * Catégorie « Beauté & soins » : validation, persistance et sérialisation.
 * RefreshDatabase : à exécuter uniquement contre une base de test dédiée.
 */
class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private const BEAUTY = 'Beauté & soins';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Coiffure',
            'amount' => 15000,
            'category' => self::BEAUTY,
            'date' => now()->toDateString(),
        ], $overrides);
    }

    private function createExpense(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'user_id' => $this->user->id,
            'description' => 'Dépense existante',
            'amount' => 5000,
            'category' => 'Nourriture',
            'date' => now()->toDateString(),
        ], $attributes));
    }

    public function test_user_can_create_an_expense_with_beaute_et_soins(): void
    {
        $response = $this->postJson('/api/expenses', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.category', self::BEAUTY)
            ->assertJsonPath('data.category_icon', '💅');

        $this->assertDatabaseHas('expenses', [
            'user_id' => $this->user->id,
            'category' => self::BEAUTY,
        ]);
    }

    public function test_persisted_category_is_byte_identical(): void
    {
        $this->postJson('/api/expenses', $this->payload())->assertCreated();

        $stored = Expense::where('user_id', $this->user->id)->firstOrFail()->category;

        $this->assertSame(self::BEAUTY, $stored);
        $this->assertSame('4265617574c3a9202620736f696e73', bin2hex($stored));
    }

    public function test_user_can_update_an_expense_to_beaute_et_soins(): void
    {
        $expense = $this->createExpense();

        $this->putJson("/api/expenses/{$expense->id}", ['category' => self::BEAUTY])
            ->assertOk()
            ->assertJsonPath('data.category', self::BEAUTY);

        $this->assertSame(self::BEAUTY, $expense->fresh()->category);
    }

    public function test_unknown_category_is_rejected(): void
    {
        $this->postJson('/api/expenses', $this->payload(['category' => 'Autre']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);

        $expense = $this->createExpense();

        $this->putJson("/api/expenses/{$expense->id}", ['category' => 'Autre'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);

        $this->assertSame('Nourriture', $expense->fresh()->category);
    }

    public function test_existing_categories_are_still_accepted(): void
    {
        foreach (['Nourriture', 'Transport', 'Factures', 'Loisirs', 'Imprévu'] as $category) {
            $this->postJson('/api/expenses', $this->payload(['category' => $category]))
                ->assertCreated()
                ->assertJsonPath('data.category', $category);
        }

        $this->assertSame(5, Expense::where('user_id', $this->user->id)->count());
    }

    public function test_expense_list_serializes_category_unchanged(): void
    {
        $this->createExpense(['category' => self::BEAUTY]);

        $this->getJson('/api/expenses')
            ->assertOk()
            ->assertJsonPath('data.0.category', self::BEAUTY);
    }

    public function test_dashboard_categories_groups_beaute_et_soins(): void
    {
        $this->createExpense(['category' => self::BEAUTY, 'amount' => 3000]);
        $this->createExpense(['category' => self::BEAUTY, 'amount' => 1000]);
        $this->createExpense(['category' => 'Transport', 'amount' => 4000]);

        $categories = collect($this->getJson('/api/dashboard/categories')->assertOk()->json('data'))
            ->keyBy('nom');

        $this->assertCount(2, $categories);
        $this->assertEquals(4000, $categories[self::BEAUTY]['montant']);
        $this->assertEquals(50, $categories[self::BEAUTY]['pourcentage']);
    }

    public function test_recent_transactions_return_category_unchanged(): void
    {
        $this->createExpense(['category' => self::BEAUTY]);

        $this->getJson('/api/dashboard/recent-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.category', self::BEAUTY);
    }
}
