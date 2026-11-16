<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\AdminUserIndexRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request as HttpTestRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * BE-06 — GET /api/admin/users (liste paginée du répertoire utilisateurs).
 *
 * EXÉCUTION DIFFÉRÉE — CONTRAINTES :
 * - L'isolation OPS-02 n'est pas encore démontrée : ces parcours ne
 *   s'appuient volontairement sur aucune base de données (ni factory,
 *   ni RefreshDatabase, ni requête SQL réelle).
 * - vendor/ est absent du worktree : l'exécution requiert un environnement
 *   composer installé par le propriétaire (aucun `composer install` autorisé
 *   dans cette mission) et une MariaDB `*_test` isolée conformément au
 *   phpunit.xml du worktree.
 * - Les parcours HTTP avec session Super Admin + grant MFA authentifié
 *   (200 avec grant MFA valide, 403 origin étrangère, 503 non activé)
 *   sont couverts par le harnais autonome dédié (tests/AdminUsersHttp) et
 *   par les suites existantes (tests/AdminFoundation + tests/AdminMfa).
 *
 * Couvert ici sans DB : les règles de validation 422 et la liste blanche
 * de la Resource (aucun secret, aucune donnée hors contrat, aucune valeur
 * BE-03 inventée).
 */
final class AdminUsersIndexTest extends TestCase
{
    public function test_pagination_parameters_accept_defaults_and_bounds(): void
    {
        $this->assertPasses([]);
        $this->assertPasses(['page' => '1', 'per_page' => '15']);
        $this->assertPasses(['page' => '42', 'per_page' => '50']);
        $this->assertPasses(['q' => 'ricard']);
    }

    public function test_pagination_parameters_reject_out_of_bounds_and_non_integer(): void
    {
        $this->assertFails(['page' => '0']);
        $this->assertFails(['page' => '-1']);
        $this->assertFails(['page' => 'abc']);
        $this->assertFails(['per_page' => '0']);
        $this->assertFails(['per_page' => '51']);
        $this->assertFails(['per_page' => 'abc']);
        // q doit rester un scalaire borné.
        $this->assertFails(['q' => ['array']]);
        $this->assertFails(['q' => str_repeat('a', 101)]);
    }

    public function test_resource_reveals_only_whitelisted_keys_without_be03_fields(): void
    {
        $user = (new User)->forceFill([
            'id' => 7,
            'name' => 'R. Test',
            'email' => 'r.test@example.test',
            'password' => 'should-never-appear',
            'remember_token' => 'should-never-appear',
        ]);

        $data = (new AdminUserResource($user))
            ->toArray(HttpTestRequest::create('/api/admin/users'));

        // Contrat liste pré-BE-03 : les clés BE-03 (administrative_status,
        // auth_version, suspended_at) sont omises — aucune valeur inventée,
        // aucune clé hors contrat. La clé JSON « nom » est alimentée par
        // la colonne User.name.
        $this->assertSame(
            ['id', 'nom', 'email', 'created_at'],
            array_keys($data)
        );
        $this->assertSame(7, $data['id']);
        $this->assertSame('R. Test', $data['nom']);
        $this->assertSame('r.test@example.test', $data['email']);
        $this->assertNotContains('password', array_keys($data));
        $this->assertNotContains('remember_token', array_keys($data));
        $this->assertNotContains('suspension_reason', array_keys($data));

        // Sérialisation complète : rien de sensible n'échappe à la liste blanche.
        $this->assertStringNotContainsString('should-never-appear', json_encode($data) ?: '');
    }

    /* Helpers ------------------------------------------------------------- */

    private function assertPasses(array $data): void
    {
        $validator = Validator::make($data, (new AdminUserIndexRequest)->rules());
        $this->assertTrue(
            $validator->passes(),
            'Échec inattendu : '.$validator->errors()->toJson()
        );
    }

    private function assertFails(array $data): void
    {
        $validator = Validator::make($data, (new AdminUserIndexRequest)->rules());
        $this->assertTrue(
            $validator->fails(),
            '422 attendu pour : '.json_encode($data)
        );
    }
}
