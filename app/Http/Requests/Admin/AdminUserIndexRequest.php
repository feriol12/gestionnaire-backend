<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BE-06 — validation des paramètres de GET /api/admin/users.
 *
 * Toute valeur invalide produit une 422 (échec de validation Laravel,
 * réponse JSON automatique). Aucun paramètre métier au-delà du contrat :
 * page, per_page, recherche facultative q.
 */
class AdminUserIndexRequest extends FormRequest
{
    /**
     * L'autorisation (identité Super Admin + preuve MFA) est entièrement
     * assurée par la chaîne de middleware de routes/admin.php :
     * AdminMfaEnabled, SelectAdminContext, AdminCsrf, RequireAdminIdentity,
     * RequireAdminMfa. Aucune décision d'autorisation ici.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            // Contrat : per_page défaut 15, bornes min=1 / max=50.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            // Recherche facultative sur name/email ; longueur bornée pour
            // éviter les requêtes LIKE dégénérées.
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
