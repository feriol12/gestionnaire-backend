<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * BE-03 — corps de POST /api/admin/users/{id}/suspend|reactivate (GOV-05 §3).
 *
 * reason : requis, non vide après trim (TrimStrings global), ≤ 2 000 caractères.
 * confirm_email : requis ; l'égalité exacte avec l'adresse de la cible est
 * vérifiée côté serveur, sous verrou, par ClientAccountTransitions.
 *
 * L'autorisation (Super Admin, grant MFA, CSRF/origine) est portée par la
 * pile de routes/admin.php ; la preuve MFA récente l'est dans la transaction.
 */
class AdminUserTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'confirm_email' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * 422 JSON dans tous les cas : jamais de redirection ni de flash de
     * session qui pourrait recopier le motif (SEC-01 S16), et aucun écho des
     * valeurs soumises.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Les données fournies sont invalides.',
            'errors' => $validator->errors()->toArray(),
        ], 422, ['Cache-Control' => 'no-store, private']));
    }
}
