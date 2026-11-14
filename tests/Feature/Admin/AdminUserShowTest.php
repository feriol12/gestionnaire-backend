<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request as HttpTestRequest;
use Tests\TestCase;

/**
 * BE-06 — GET /api/admin/users/{id} (détail du répertoire utilisateurs).
 *
 * EXÉCUTION DIFFÉRÉE — CONTRAINTES :
 * - Aucun accès base de données (isolation OPS-02 non démontrée, vendor/
 *   absent du worktree : aucun composer install) : ici, uniquement la
 *   liste blanche du détail sur des modèles hydratés en mémoire.
 * - Le parcours HTTP 404 (USER_NOT_FOUND via find() en base) et le
 *   parcours authentifié 200 sont à exécuter ensuite dans le harnais
 *   admin dédié, dès que l'isolation de test est démontrée.
 *
 * Trois scénarios de détection des champs portés par BE-03 :
 *   1. colonne absente          ⇒ clé OMISE (aucune valeur inventée) ;
 *   2. colonne présente + NULL  ⇒ clé EXPOSÉE à null ;
 *   3. colonne présente + valeur ⇒ clé EXPOSÉE avec la valeur.
 */
final class AdminUserShowTest extends TestCase
{
    public function test_detail_omits_be03_fields_when_columns_are_absent(): void
    {
        $user = (new User)->forceFill([
            'id' => 3,
            'name' => 'J. Exemple',
            'email' => 'j.exemple@example.test',
            'password' => 'should-never-appear',
            'remember_token' => 'should-never-appear',
        ]);

        $data = (new AdminUserResource($user))
            ->toArray(HttpTestRequest::create('/api/admin/users/3'));

        // Pré-BE-03 : identique à la liste, clés BE-03 omises, rien inventé.
        $this->assertSame(
            ['id', 'nom', 'email', 'created_at'],
            array_keys($data)
        );
        // Contrat : la clé JSON « nom » est alimentée par User.name.
        $this->assertSame('J. Exemple', $data['nom']);
        $this->assertStringNotContainsString('should-never-appear', json_encode($data) ?: '');
    }

    public function test_detail_exposes_null_when_be03_columns_exist_but_are_null(): void
    {
        $user = (new User)->forceFill([
            'id' => 3,
            'name' => 'J. Exemple',
            'email' => 'j.exemple@example.test',
            // Colonnes présentes (côté schéma BE-03) mais non renseignées :
            // les clés doivent apparaître avec null, pas disparaître.
            'administrative_status' => null,
            'auth_version' => null,
            'suspended_at' => null,
        ]);

        $data = (new AdminUserResource($user))
            ->toArray(HttpTestRequest::create('/api/admin/users/3'));

        $this->assertSame(
            ['id', 'nom', 'email', 'created_at', 'administrative_status', 'auth_version', 'suspended_at'],
            array_keys($data)
        );
        $this->assertNull($data['administrative_status']);
        $this->assertNull($data['auth_version']);
        $this->assertNull($data['suspended_at']);
    }

    public function test_detail_exposes_values_when_be03_columns_are_filled(): void
    {
        $user = (new User)->forceFill([
            'id' => 3,
            'name' => 'J. Exemple',
            'email' => 'j.exemple@example.test',
            'administrative_status' => 'be03_placeholder',
            'auth_version' => 1,
            'suspended_at' => '2026-01-01 10:00:00',
        ]);

        $data = (new AdminUserResource($user))
            ->toArray(HttpTestRequest::create('/api/admin/users/3'));

        $this->assertSame(
            ['id', 'nom', 'email', 'created_at', 'administrative_status', 'auth_version', 'suspended_at'],
            array_keys($data)
        );
        $this->assertSame('be03_placeholder', $data['administrative_status']);
        $this->assertSame(1, $data['auth_version']);
        // Sans cast BE-03 forcé, la valeur brute est reprise telle quelle.
        $this->assertSame('2026-01-01 10:00:00', $data['suspended_at']);
    }
}
