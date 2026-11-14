<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUserIndexRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BE-06 — consultation LECTURE SEULE du répertoire utilisateurs pour le
 * Super Admin. Aucune mutation (pas de suspension, réactivation,
 * suppression ou modification métier), aucune donnée financière, aucun
 * champ hors contrat.
 *
 * Les colonnes du contrat portées par BE-03 (administrative_status,
 * auth_version, suspended_at) ne sont pas encore disponibles : la
 * réponse NE SATISFAIT PAS encore le contrat complet — voir
 * AdminUserResource, qui les omet au lieu d'inventer des valeurs.
 *
 * Autorisation : entièrement déléguée à la chaîne de middleware admin
 * existante (RequireAdminIdentity + RequireAdminMfa). Aucun mécanisme
 * d'authentification concurrent n'est créé.
 */
final class AdminUserController extends Controller
{
    /**
     * GET /api/admin/users
     * Pagination : page (défaut 1), per_page (défaut 15, max 50 — 422 hors bornes).
     * Recherche facultative q sur les colonnes name / email, jokers LIKE échappés.
     *
     * @param  AdminUserIndexRequest  $request
     * @return JsonResponse
     */
    public function index(AdminUserIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);
        $page = (int) ($validated['page'] ?? 1);

        $users = User::query()
            ->when(! empty($validated['q']), function ($query) use ($validated): void {
                // Échappement explicite des jokers SQL LIKE et du caractère
                // d'échappement lui-même.
                $needle = addcslashes((string) $validated['q'], '\\%_');
                $query->where(function ($outer) use ($needle): void {
                    $outer->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('email', 'like', '%'.$needle.'%');
                });
            })
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->respond(
            AdminUserResource::collection($users),
            $request
        );
    }

    /**
     * GET /api/admin/users/{id}
     * 404 codé USER_NOT_FOUND si l'utilisateur n'existe pas. La route
     * impose whereNumber('id') : les identifiants non numériques sont
     * traités comme inexistants (404), jamais comme une recherche libre.
     *
     * @param  Request  $request
     * @param  string  $id
     * @return JsonResponse
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = User::query()->find((int) $id);

        if ($user === null) {
            return response()->json(
                ['code' => 'USER_NOT_FOUND'],
                404,
                ['Cache-Control' => 'no-store, private']
            );
        }

        return $this->respond(new AdminUserResource($user), $request);
    }

    /**
     * Conventions du répertoire admin (AdminAuthController::result) :
     * jeton CSRF renouvelé dans la charge et Cache-Control anti-cache.
     *
     * @param  JsonResource|AnonymousResourceCollection  $resource
     * @param  Request  $request
     * @return JsonResponse
     */
    private function respond(JsonResource|AnonymousResourceCollection $resource, Request $request): JsonResponse
    {
        $response = $resource
            ->additional(['csrf' => $request->session()->token()])
            ->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
