# FM-BE-06-02 - Full diff of the BE-06 change set (R4 final)

Base: feature/fm-admin-be-06-users-api @ 9d70a34d5c1ce4c5a17b94f6c36ca6cfde999003 (origin/integration/security-auth-v3 head is identical).

Final regeneration on 2026-11-14 (after the KiloCode R4 review approval). The harness documents and implements the following hardening items:
- classic repository and Git Worktree support (gitdir pointer resolution, absolute or relative, HEAD check);
- safe creation of the dedicated SQLite file ONLY when absent (never overwritten; explicit failure handling);
- Laravel-conventional runtime dirs framework/sessions, framework/cache/data and framework/views;
- Console Kernel bootstrap before the admin route-surface check;
- evidence JSON written to storage/app/admin-be06-users/runtime/evidence/;
- Sanctum client CSRF convention: XSRF-TOKEN cookie echoed as the HTTP_X_XSRF_TOKEN header.

## 1. routes/admin.php (tracked file, standard git diff vs base 9d70a34)

```diff
diff --git a/routes/admin.php b/routes/admin.php
index 813d25a..9e062d3 100644
--- a/routes/admin.php
+++ b/routes/admin.php
@@ -1,6 +1,7 @@
 <?php
 
 use App\Http\Controllers\Admin\AdminAuthController as Controller;
+use App\Http\Controllers\Admin\AdminUserController;
 use App\Http\Middleware;
 use Illuminate\Support\Facades\Route;
 
@@ -23,5 +24,8 @@
         Route::get('session', [Controller::class, 'session']);
         Route::post('reauthenticate', [Controller::class, 'reauthenticate']);
         Route::post('factor/change', [Controller::class, 'change']);
+        // BE-06 — read-only user directory. No mutation, no finance data.
+        Route::get('users', [AdminUserController::class, 'index']);
+        Route::get('users/{id}', [AdminUserController::class, 'show'])->whereNumber('id');
     });
 });
```

## 2. app/Http/Controllers/Admin/AdminUserController.php (new file, --no-index hunks, exact current content)

```diff
diff --git a/app/Http/Controllers/Admin/AdminUserController.php b/app/Http/Controllers/Admin/AdminUserController.php
new file mode 100644
index 0000000..75d2497
--- /dev/null
+++ b/app/Http/Controllers/Admin/AdminUserController.php
@@ -0,0 +1,106 @@
+<?php
+
+namespace App\Http\Controllers\Admin;
+
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Admin\AdminUserIndexRequest;
+use App\Http\Resources\Admin\AdminUserResource;
+use App\Models\User;
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * BE-06 — consultation LECTURE SEULE du répertoire utilisateurs pour le
+ * Super Admin. Aucune mutation (pas de suspension, réactivation,
+ * suppression ou modification métier), aucune donnée financière, aucun
+ * champ hors contrat.
+ *
+ * Les colonnes du contrat portées par BE-03 (administrative_status,
+ * auth_version, suspended_at) ne sont pas encore disponibles : la
+ * réponse NE SATISFAIT PAS encore le contrat complet — voir
+ * AdminUserResource, qui les omet au lieu d'inventer des valeurs.
+ *
+ * Autorisation : entièrement déléguée à la chaîne de middleware admin
+ * existante (RequireAdminIdentity + RequireAdminMfa). Aucun mécanisme
+ * d'authentification concurrent n'est créé.
+ */
+final class AdminUserController extends Controller
+{
+    /**
+     * GET /api/admin/users
+     * Pagination : page (défaut 1), per_page (défaut 15, max 50 — 422 hors bornes).
+     * Recherche facultative q sur les colonnes name / email, jokers LIKE échappés.
+     *
+     * @param  AdminUserIndexRequest  $request
+     * @return JsonResponse
+     */
+    public function index(AdminUserIndexRequest $request): JsonResponse
+    {
+        $validated = $request->validated();
+        $perPage = (int) ($validated['per_page'] ?? 15);
+        $page = (int) ($validated['page'] ?? 1);
+
+        $users = User::query()
+            ->when(! empty($validated['q']), function ($query) use ($validated): void {
+                // Échappement explicite des jokers SQL LIKE et du caractère
+                // d'échappement lui-même.
+                $needle = addcslashes((string) $validated['q'], '\\%_');
+                $query->where(function ($outer) use ($needle): void {
+                    $outer->where('name', 'like', '%'.$needle.'%')
+                        ->orWhere('email', 'like', '%'.$needle.'%');
+                });
+            })
+            ->orderBy('id')
+            ->paginate($perPage, ['*'], 'page', $page);
+
+        return $this->respond(
+            AdminUserResource::collection($users),
+            $request
+        );
+    }
+
+    /**
+     * GET /api/admin/users/{id}
+     * 404 codé USER_NOT_FOUND si l'utilisateur n'existe pas. La route
+     * impose whereNumber('id') : les identifiants non numériques sont
+     * traités comme inexistants (404), jamais comme une recherche libre.
+     *
+     * @param  Request  $request
+     * @param  string  $id
+     * @return JsonResponse
+     */
+    public function show(Request $request, string $id): JsonResponse
+    {
+        $user = User::query()->find((int) $id);
+
+        if ($user === null) {
+            return response()->json(
+                ['code' => 'USER_NOT_FOUND'],
+                404,
+                ['Cache-Control' => 'no-store, private']
+            );
+        }
+
+        return $this->respond(new AdminUserResource($user), $request);
+    }
+
+    /**
+     * Conventions du répertoire admin (AdminAuthController::result) :
+     * jeton CSRF renouvelé dans la charge et Cache-Control anti-cache.
+     *
+     * @param  JsonResource|AnonymousResourceCollection  $resource
+     * @param  Request  $request
+     * @return JsonResponse
+     */
+    private function respond(JsonResource|AnonymousResourceCollection $resource, Request $request): JsonResponse
+    {
+        $response = $resource
+            ->additional(['csrf' => $request->session()->token()])
+            ->toResponse($request);
+        $response->headers->set('Cache-Control', 'no-store, private');
+
+        return $response;
+    }
+}
```

## 3. app/Http/Requests/Admin/AdminUserIndexRequest.php (new file, --no-index hunks, exact current content)

```diff
diff --git a/app/Http/Requests/Admin/AdminUserIndexRequest.php b/app/Http/Requests/Admin/AdminUserIndexRequest.php
new file mode 100644
index 0000000..310b3a9
--- /dev/null
+++ b/app/Http/Requests/Admin/AdminUserIndexRequest.php
@@ -0,0 +1,41 @@
+<?php
+
+namespace App\Http\Requests\Admin;
+
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * BE-06 — validation des paramètres de GET /api/admin/users.
+ *
+ * Toute valeur invalide produit une 422 (échec de validation Laravel,
+ * réponse JSON automatique). Aucun paramètre métier au-delà du contrat :
+ * page, per_page, recherche facultative q.
+ */
+class AdminUserIndexRequest extends FormRequest
+{
+    /**
+     * L'autorisation (identité Super Admin + preuve MFA) est entièrement
+     * assurée par la chaîne de middleware de routes/admin.php :
+     * AdminMfaEnabled, SelectAdminContext, AdminCsrf, RequireAdminIdentity,
+     * RequireAdminMfa. Aucune décision d'autorisation ici.
+     */
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
+     */
+    public function rules(): array
+    {
+        return [
+            'page' => ['nullable', 'integer', 'min:1'],
+            // Contrat : per_page défaut 15, bornes min=1 / max=50.
+            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
+            // Recherche facultative sur name/email ; longueur bornée pour
+            // éviter les requêtes LIKE dégénérées.
+            'q' => ['nullable', 'string', 'max:100'],
+        ];
+    }
+}
```

## 4. app/Http/Resources/Admin/AdminUserResource.php (new file, --no-index hunks, exact current content)

```diff
diff --git a/app/Http/Resources/Admin/AdminUserResource.php b/app/Http/Resources/Admin/AdminUserResource.php
new file mode 100644
index 0000000..9f5344c
--- /dev/null
+++ b/app/Http/Resources/Admin/AdminUserResource.php
@@ -0,0 +1,86 @@
+<?php
+
+namespace App\Http\Resources\Admin;
+
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * BE-06 — liste blanche stricte du contrat API :
+ *
+ *   liste  : id, nom, email, administrative_status, auth_version, created_at
+ *   détail : les mêmes champs + suspended_at
+ *
+ * Mapping : la clé JSON « nom » est alimentée par la colonne User.name.
+ *
+ * POINT IMPORTANT BE-03 : les colonnes administrative_status, auth_version
+ * (côté utilisateur) et suspended_at n'existent pas encore sur `users`.
+ * Tant que la migration BE-03 n'est pas livrée, ces trois clés sont
+ * omises — aucune valeur n'est inventée et la réponse NE SATISFAIT PAS
+ * encore le contrat complet. L'émission de chaque clé est pilotée par
+ * hasColumn(), de sorte qu'aucun changement ne soit nécessaire ici
+ * lorsque BE-03 livrera les colonnes.
+ *
+ * password, remember_token, suspension_reason (motif interne) et toute
+ * donnée financière ne sont jamais sérialisés — liste blanche, jamais
+ * une liste noire.
+ *
+ * @property \App\Models\User $resource
+ */
+class AdminUserResource extends JsonResource
+{
+    /**
+     * @return array<string, mixed>
+     */
+    public function toArray(Request $request): array
+    {
+        $data = [
+            'id' => $this->id,
+            // Contrat : la clé JSON est « nom », colonne source User.name.
+            'nom' => $this->name,
+            'email' => $this->email,
+            // Instance datetime (cast Laravel natif des timestamps).
+            'created_at' => $this->created_at?->toISOString(),
+        ];
+
+        // Champs du contrat portés par BE-03 : émis selon hasColumn()
+        // (colonne absente ⇒ clé omise ; colonne présente ⇒ clé émise,
+        // y compris avec la valeur null explicite).
+        if ($this->hasColumn('administrative_status')) {
+            $data['administrative_status'] = $this->administrative_status;
+        }
+
+        if ($this->hasColumn('auth_version')) {
+            $data['auth_version'] = $this->auth_version;
+        }
+
+        if ($this->hasColumn('suspended_at')) {
+            $suspendedAt = $this->suspended_at;
+            // Selon la migration BE-03, la colonne sera datetime (cast) ou non :
+            // on sérialise en ISO 8601 uniquement si l'instance est déjà une
+            // date, sinon la valeur brute (string ou null) est reprise telle
+            // quelle — aucune transformation approximative, aucune invention.
+            $data['suspended_at'] = $suspendedAt instanceof \DateTimeInterface
+                ? $suspendedAt->format(DATE_RFC3339_EXTENDED)
+                : $suspendedAt;
+        }
+
+        return $data;
+    }
+
+    /**
+     * Détection exacte de la présence de la colonne côté modèle.
+     *
+     * Eloquent::offsetExists() / isset($model->champ) confondent
+     * « attribut présent mais NULL » et « attribut absent » (implémentation
+     * `! is_null($this->{$offset})`) : les deux cas doivent être distingués
+     * — un attribut NULL est exposé comme null JSON, un attribut
+     * absent (colonne inexistante) est omis. On interroge donc les
+     * attributs bruts du modèle, qui reflètent exactement les colonnes
+     * retournées par la requête SQL.
+     */
+    private function hasColumn(string $field): bool
+    {
+        return array_key_exists($field, $this->resource->getAttributes());
+    }
+}
```

## 5. tests/Feature/Admin/AdminUsersIndexTest.php (new file, --no-index hunks, exact current content)

```diff
diff --git a/tests/Feature/Admin/AdminUsersIndexTest.php b/tests/Feature/Admin/AdminUsersIndexTest.php
new file mode 100644
index 0000000..7abebee
--- /dev/null
+++ b/tests/Feature/Admin/AdminUsersIndexTest.php
@@ -0,0 +1,106 @@
+<?php
+
+namespace Tests\Feature\Admin;
+
+use App\Http\Requests\Admin\AdminUserIndexRequest;
+use App\Http\Resources\Admin\AdminUserResource;
+use App\Models\User;
+use Illuminate\Http\Request as HttpTestRequest;
+use Illuminate\Support\Facades\Validator;
+use Tests\TestCase;
+
+/**
+ * BE-06 — GET /api/admin/users (liste paginée du répertoire utilisateurs).
+ *
+ * EXÉCUTION DIFFÉRÉE — CONTRAINTES :
+ * - L'isolation OPS-02 n'est pas encore démontrée : ces parcours ne
+ *   s'appuient volontairement sur aucune base de données (ni factory,
+ *   ni RefreshDatabase, ni requête SQL réelle).
+ * - vendor/ est absent du worktree : l'exécution requiert un environnement
+ *   composer installé par le propriétaire (aucun `composer install` autorisé
+ *   dans cette mission) et une MariaDB `*_test` isolée conformément au
+ *   phpunit.xml du worktree.
+ * - Les parcours HTTP avec session Super Admin + grant MFA authentifié
+ *   (200 avec grant MFA valide, 403 origin étrangère, 503 non activé)
+ *   sont couverts par le harnais autonome dédié (tests/AdminUsersHttp) et
+ *   par les suites existantes (tests/AdminFoundation + tests/AdminMfa).
+ *
+ * Couvert ici sans DB : les règles de validation 422 et la liste blanche
+ * de la Resource (aucun secret, aucune donnée hors contrat, aucune valeur
+ * BE-03 inventée).
+ */
+final class AdminUsersIndexTest extends TestCase
+{
+    public function test_pagination_parameters_accept_defaults_and_bounds(): void
+    {
+        $this->assertPasses([]);
+        $this->assertPasses(['page' => '1', 'per_page' => '15']);
+        $this->assertPasses(['page' => '42', 'per_page' => '50']);
+        $this->assertPasses(['q' => 'ricard']);
+    }
+
+    public function test_pagination_parameters_reject_out_of_bounds_and_non_integer(): void
+    {
+        $this->assertFails(['page' => '0']);
+        $this->assertFails(['page' => '-1']);
+        $this->assertFails(['page' => 'abc']);
+        $this->assertFails(['per_page' => '0']);
+        $this->assertFails(['per_page' => '51']);
+        $this->assertFails(['per_page' => 'abc']);
+        // q doit rester un scalaire borné.
+        $this->assertFails(['q' => ['array']]);
+        $this->assertFails(['q' => str_repeat('a', 101)]);
+    }
+
+    public function test_resource_reveals_only_whitelisted_keys_without_be03_fields(): void
+    {
+        $user = (new User)->forceFill([
+            'id' => 7,
+            'name' => 'R. Test',
+            'email' => 'r.test@example.test',
+            'password' => 'should-never-appear',
+            'remember_token' => 'should-never-appear',
+        ]);
+
+        $data = (new AdminUserResource($user))
+            ->toArray(HttpTestRequest::create('/api/admin/users'));
+
+        // Contrat liste pré-BE-03 : les clés BE-03 (administrative_status,
+        // auth_version, suspended_at) sont omises — aucune valeur inventée,
+        // aucune clé hors contrat. La clé JSON « nom » est alimentée par
+        // la colonne User.name.
+        $this->assertSame(
+            ['id', 'nom', 'email', 'created_at'],
+            array_keys($data)
+        );
+        $this->assertSame(7, $data['id']);
+        $this->assertSame('R. Test', $data['nom']);
+        $this->assertSame('r.test@example.test', $data['email']);
+        $this->assertNotContains('password', array_keys($data));
+        $this->assertNotContains('remember_token', array_keys($data));
+        $this->assertNotContains('suspension_reason', array_keys($data));
+
+        // Sérialisation complète : rien de sensible n'échappe à la liste blanche.
+        $this->assertStringNotContainsString('should-never-appear', json_encode($data) ?: '');
+    }
+
+    /* Helpers ------------------------------------------------------------- */
+
+    private function assertPasses(array $data): void
+    {
+        $validator = Validator::make($data, (new AdminUserIndexRequest)->rules());
+        $this->assertTrue(
+            $validator->passes(),
+            'Échec inattendu : '.$validator->errors()->toJson()
+        );
+    }
+
+    private function assertFails(array $data): void
+    {
+        $validator = Validator::make($data, (new AdminUserIndexRequest)->rules());
+        $this->assertTrue(
+            $validator->fails(),
+            '422 attendu pour : '.json_encode($data)
+        );
+    }
+}
```

## 6. tests/Feature/Admin/AdminUserShowTest.php (new file, --no-index hunks, exact current content)

```diff
diff --git a/tests/Feature/Admin/AdminUserShowTest.php b/tests/Feature/Admin/AdminUserShowTest.php
new file mode 100644
index 0000000..131f4a5
--- /dev/null
+++ b/tests/Feature/Admin/AdminUserShowTest.php
@@ -0,0 +1,99 @@
+<?php
+
+namespace Tests\Feature\Admin;
+
+use App\Http\Resources\Admin\AdminUserResource;
+use App\Models\User;
+use Illuminate\Http\Request as HttpTestRequest;
+use Tests\TestCase;
+
+/**
+ * BE-06 — GET /api/admin/users/{id} (détail du répertoire utilisateurs).
+ *
+ * EXÉCUTION DIFFÉRÉE — CONTRAINTES :
+ * - Aucun accès base de données (isolation OPS-02 non démontrée, vendor/
+ *   absent du worktree : aucun composer install) : ici, uniquement la
+ *   liste blanche du détail sur des modèles hydratés en mémoire.
+ * - Le parcours HTTP 404 (USER_NOT_FOUND via find() en base) et le
+ *   parcours authentifié 200 sont à exécuter ensuite dans le harnais
+ *   admin dédié, dès que l'isolation de test est démontrée.
+ *
+ * Trois scénarios de détection des champs portés par BE-03 :
+ *   1. colonne absente          ⇒ clé OMISE (aucune valeur inventée) ;
+ *   2. colonne présente + NULL  ⇒ clé EXPOSÉE à null ;
+ *   3. colonne présente + valeur ⇒ clé EXPOSÉE avec la valeur.
+ */
+final class AdminUserShowTest extends TestCase
+{
+    public function test_detail_omits_be03_fields_when_columns_are_absent(): void
+    {
+        $user = (new User)->forceFill([
+            'id' => 3,
+            'name' => 'J. Exemple',
+            'email' => 'j.exemple@example.test',
+            'password' => 'should-never-appear',
+            'remember_token' => 'should-never-appear',
+        ]);
+
+        $data = (new AdminUserResource($user))
+            ->toArray(HttpTestRequest::create('/api/admin/users/3'));
+
+        // Pré-BE-03 : identique à la liste, clés BE-03 omises, rien inventé.
+        $this->assertSame(
+            ['id', 'nom', 'email', 'created_at'],
+            array_keys($data)
+        );
+        // Contrat : la clé JSON « nom » est alimentée par User.name.
+        $this->assertSame('J. Exemple', $data['nom']);
+        $this->assertStringNotContainsString('should-never-appear', json_encode($data) ?: '');
+    }
+
+    public function test_detail_exposes_null_when_be03_columns_exist_but_are_null(): void
+    {
+        $user = (new User)->forceFill([
+            'id' => 3,
+            'name' => 'J. Exemple',
+            'email' => 'j.exemple@example.test',
+            // Colonnes présentes (côté schéma BE-03) mais non renseignées :
+            // les clés doivent apparaître avec null, pas disparaître.
+            'administrative_status' => null,
+            'auth_version' => null,
+            'suspended_at' => null,
+        ]);
+
+        $data = (new AdminUserResource($user))
+            ->toArray(HttpTestRequest::create('/api/admin/users/3'));
+
+        $this->assertSame(
+            ['id', 'nom', 'email', 'created_at', 'administrative_status', 'auth_version', 'suspended_at'],
+            array_keys($data)
+        );
+        $this->assertNull($data['administrative_status']);
+        $this->assertNull($data['auth_version']);
+        $this->assertNull($data['suspended_at']);
+    }
+
+    public function test_detail_exposes_values_when_be03_columns_are_filled(): void
+    {
+        $user = (new User)->forceFill([
+            'id' => 3,
+            'name' => 'J. Exemple',
+            'email' => 'j.exemple@example.test',
+            'administrative_status' => 'be03_placeholder',
+            'auth_version' => 1,
+            'suspended_at' => '2026-01-01 10:00:00',
+        ]);
+
+        $data = (new AdminUserResource($user))
+            ->toArray(HttpTestRequest::create('/api/admin/users/3'));
+
+        $this->assertSame(
+            ['id', 'nom', 'email', 'created_at', 'administrative_status', 'auth_version', 'suspended_at'],
+            array_keys($data)
+        );
+        $this->assertSame('be03_placeholder', $data['administrative_status']);
+        $this->assertSame(1, $data['auth_version']);
+        // Sans cast BE-03 forcé, la valeur brute est reprise telle quelle.
+        $this->assertSame('2026-01-01 10:00:00', $data['suspended_at']);
+    }
+}
```

## 7. tests/AdminUsersHttp/bootstrap.php (new file, --no-index hunks, exact current content)

```diff
diff --git a/tests/AdminUsersHttp/bootstrap.php b/tests/AdminUsersHttp/bootstrap.php
new file mode 100644
index 0000000..d4458f7
--- /dev/null
+++ b/tests/AdminUsersHttp/bootstrap.php
@@ -0,0 +1,121 @@
+<?php
+
+/*
+ * BE-06 — admin users HTTP/MFA integration harness bootstrap.
+ *
+ * Conventions copied from the existing standalone tests/AdminMfa harness:
+ * a fresh application per request, isolated runtime storage, explicit
+ * environment values, no dependence on the developer workstation state.
+ * This file creates nothing outside its own runtime (storage/app/
+ * admin-be06-users) and does not modify any production or foundational file.
+ *
+ * OPS-02 gate: run.php refuses to execute without the explicit 'run'
+ * argument. A database-backed execution stays reserved to a qualified
+ * and owner-validated isolation profile.
+ */
+
+// Accept both layouts: a classic repository has .git/HEAD directly, while
+// a Git worktree has .git as a FILE containing "gitdir: <path>" (absolute,
+// or relative to the checkout root). Resolve the pointer and require the
+// real Git directory to contain HEAD.
+$root = dirname(__DIR__, 2);
+$gitPath = $root.'/.git';
+if (is_file($gitPath.'/HEAD')) {
+    // Classic checkout: .git is the Git directory itself.
+    $gitDir = $gitPath;
+} else {
+    $pointer = is_file($gitPath) ? trim((string) file_get_contents($gitPath)) : '';
+    if (!str_starts_with($pointer, 'gitdir:')) {
+        throw new RuntimeException('Independent BE-06 checkout required');
+    }
+    $target = trim(substr($pointer, strlen('gitdir:')));
+    if ($target === '') {
+        throw new RuntimeException('Independent BE-06 checkout required');
+    }
+    // Absolute pointer (drive letter, UNC share or POSIX root) is used as
+    // is; anything else is resolved relative to the checkout root. realpath()
+    // canonicalizes (../ segments, separators) and fails on a dead pointer.
+    $absolute = preg_match('#^(?:[A-Za-z]:[/\\\\]|\\\\\\\\|/)#', $target) === 1;
+    $gitDir = str_replace('\\', '/', rtrim((string) realpath($absolute ? $target : $root.'/'.$target), '/'));
+    if ($gitDir === '' || !is_file($gitDir.'/HEAD')) {
+        throw new RuntimeException('Independent BE-06 checkout required');
+    }
+}
+
+$runtime = $root.'/storage/app/admin-be06-users/runtime';
+foreach (['config', 'cache', 'tmp', 'sessions', 'views', 'evidence'] as $dir) {
+    if (!is_dir($runtime.'/'.$dir)) mkdir($runtime.'/'.$dir, 0700, true);
+}
+
+// Harness dirs must ALSO reproduce Laravel's conventional storage layout for
+// the default file drivers: file sessions (config/session.php defaults to
+// 'file'), file cache (CACHE_STORE=file resolves to
+// storage_path('framework/cache/data')) and compiled views. Without
+// framework/sessions the first stateful client request fails while writing
+// its session file and returns 500 instead of the expected status.
+foreach (['framework/sessions', 'framework/cache/data', 'framework/views'] as $dir) {
+    if (!is_dir($runtime.'/'.$dir)) mkdir($runtime.'/'.$dir, 0700, true);
+}
+
+// Dedicated disposable SQLite file: created EMPTY here if and only if it is
+// absent, so Laravel's first PRAGMA (busy_timeout) never hits a missing
+// database file. An existing file is NEVER overwritten, truncated or
+// recreated (fopen 'x' fails if the file already exists); the schema itself
+// stays the responsibility of run.php's initBe06Schema() behind the OPS-02
+// gate. The path stays inside this isolated runtime directory.
+$database = $runtime.'/db.sqlite';
+if (!is_file($database)) {
+    if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) {
+        throw new RuntimeException('Unable to create the BE-06 runtime directory');
+    }
+    $handle = @fopen($database, 'x');
+    if ($handle === false) {
+        // Concurrent creation is tolerable (the file must never be
+        // overwritten); only a genuinely absent file is a hard failure.
+        if (!is_file($database)) {
+            throw new RuntimeException('Unable to create the dedicated BE-06 SQLite file');
+        }
+    } else {
+        fclose($handle);
+    }
+}
+
+foreach ([
+    'APP_ENV' => 'admin-be06-test',
+    'APP_DEBUG' => 'false',
+    'APP_KEY' => 'base64:'.base64_encode(str_repeat('B', 32)),
+    'APP_URL' => 'https://client.foundation.test',
+    'FRONTEND_URL' => 'https://client.foundation.test',
+    // Dedicated disposable SQLite file: the harness stays self-contained.
+    // MariaDB/production-like runs remain reserved to the OPS-02-qualified
+    // environment and are explicitly NOT enabled here by default.
+    'DB_CONNECTION' => 'sqlite',
+    'DB_DATABASE' => $database,
+    'CACHE_STORE' => 'file',
+    'MAIL_MAILER' => 'array',
+    'QUEUE_CONNECTION' => 'sync',
+    // Production admin switches mirrored from the MFA lot: routes are only
+    // registered because mfa_enabled is true in THIS isolated runtime.
+    'ADMIN_MFA_ENABLED' => 'true',
+    'ADMIN_ORIGIN' => 'https://client.foundation.test',
+    'APP_CONFIG_CACHE' => 'storage/app/admin-be06-users/runtime/cache/config.php',
+    'APP_ROUTES_CACHE' => 'storage/app/admin-be06-users/runtime/cache/routes.php',
+    'APP_SERVICES_CACHE' => 'storage/app/admin-be06-users/runtime/cache/services.php',
+    'APP_PACKAGES_CACHE' => 'storage/app/admin-be06-users/runtime/cache/packages.php',
+    'APP_EVENTS_CACHE' => 'storage/app/admin-be06-users/runtime/cache/events.php',
+] as $key => $value) {
+    putenv($key.'='.$value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
+}
+
+require_once $root.'/vendor/autoload.php';
+
+/** Mirrors mfaApp(): a brand-new application for every request. */
+function be06App() {
+    $root = dirname(__DIR__, 2);
+    $runtime = $root.'/storage/app/admin-be06-users/runtime';
+    Illuminate\Support\Facades\Facade::clearResolvedInstances();
+    $app = require $root.'/bootstrap/app.php';
+    $app->loadEnvironmentFrom('NO-ENV-BE06-TEST');
+    $app->useStoragePath($runtime);
+    return $app;
+}
```

## 8. tests/AdminUsersHttp/run.php (new file, --no-index hunks, exact current content)

```diff
diff --git a/tests/AdminUsersHttp/run.php b/tests/AdminUsersHttp/run.php
new file mode 100644
index 0000000..418ec30
--- /dev/null
+++ b/tests/AdminUsersHttp/run.php
@@ -0,0 +1,350 @@
+<?php
+
+/*
+ * BE-06 — standalone HTTP/MFA integration harness for the read-only admin
+ * user directory (GET /api/admin/users, GET /api/admin/users/{id}).
+ *
+ * OPS-02 GATE — READ THIS BEFORE RUNNING:
+ * Running this script touches a real (disposable) database. It must only be
+ * executed AFTER the OPS-02 test isolation has been qualified and validated:
+ *
+ *     php tests/AdminUsersHttp/run.php run
+ *
+ * Without the explicit 'run' argument the script performs NOTHING: it
+ * prints SKIPPED and exits 0 without connecting to any database.
+ *
+ * Scenario coverage (contract BE-06):
+ *   1. Routes gated behind the full admin chain (AdminMfaEnabled,
+ *      SelectAdminContext, AdminCsrf, RequireAdminIdentity, RequireAdminMfa).
+ *   2. Refusal without a valid MFA grant (pre-grant 401).
+ *   3. Authorized access with a real admin session (csrf -> login ->
+ *      enrollment -> TOTP verify, identical to the Fériol MFA flow).
+ *   4. Paginated list (default page=1/per_page=15; per_page up to 50).
+ *   5. Facultative search q (columns name/email, jokers escaped).
+ *   6. 404 payload USER_NOT_FOUND for unknown and non-numeric ids.
+ *   7. 422 validation for invalid page/per_page.
+ *   8. CSRF token refusals (419) and origin/ bearer refusals (403/401).
+ *   9. Client isolation: admin session cannot reach finance endpoints,
+ *      client session cannot enter /api/admin, admin session cannot enter
+ *      /api/user, and no finance field leaks in the user payloads.
+ *  10. R2 review additions: Cache-Control: no-store, private plus refreshed
+ *      csrf on every BE-06 200 response, anti-cache header kept on the 404
+ *      refusal (csrf intentionally absent there), whitelisting swept row
+ *      by row, and a raw sensitive-leak scan on every BE-06 payload.
+ *  11. R3 review additions: the sensitive-leak scan is applied to ALL the
+ *      BE-06 200 payloads (list, per_page, page 2, both searches, detail)
+ *      and the non-numeric /users/abc refusal asserts much more than its
+ *      status (no user payload, no csrf refresh, clean scanned body).
+ */
+
+if (($argv[1] ?? '') !== 'run') {
+    echo "SKIPPED — BE-06 admin users HTTP/MFA harness. Requires an OPS-02-qualified environment; execution command: php tests/AdminUsersHttp/run.php run\n";
+    exit(0);
+}
+
+require __DIR__.'/bootstrap.php';
+use App\Models\AdminIdentity;
+use App\Models\User;
+use App\Services\Admin\AdminMfa;
+use Illuminate\Support\Facades\DB;
+
+echo "BE-06 harness — AdminIdentity, AdminMfa and middlewares by Fériol; BE-06 endpoints under review.\n";
+echo "Policy: execution allowed ONLY on the dedicated disposable SQLite file.\n";
+
+define('BE06_BASE', 'https://client.foundation.test');
+$rows = [];
+
+/** Mirror of the AdminMfa harness check(). */
+function check(string $name, $actual, $expected = true): void {
+    global $rows;
+    $pass = $actual === $expected;
+    $rows[] = ['name' => $name, 'status' => $pass ? 'PASS' : 'FAIL', 'actual' => $actual, 'expected' => $expected];
+    echo ($pass ? 'PASS ' : 'FAIL ').$name.($pass ? '' : ' [' . json_encode($actual) . ' != ' . json_encode($expected) . ']')."\n";
+}
+
+/** Mirror of req(): a fresh app per request, manual cookie jar, admin context. */
+function req(string $method, string $uri, array &$jar, array $body = [], array $extra = []): array {
+    $app = be06App();
+    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
+    $cookies = $jar; unset($cookies['_csrf']);
+    $server = [
+        'HTTPS' => 'on', 'SERVER_PORT' => 443,
+        'HTTP_ACCEPT' => 'application/json',
+        'HTTP_ORIGIN' => BE06_BASE, 'HTTP_REFERER' => BE06_BASE.'/',
+        'REMOTE_ADDR' => '127.0.0.1',
+    ];
+    if (isset($jar['_csrf'])) $server['HTTP_X_CSRF_TOKEN'] = $jar['_csrf'];
+    // Sanctum client convention: the stateful SPA pipeline (EnsureFrontend-
+    // RequestsAreStateful -> config('sanctum.middleware.validate_csrf_token')
+    // = PreventRequestForgery, AUTH3-01) expects the ENCRYPTED csrf cookie
+    // value echoed in the X-XSRF-TOKEN header, which Laravel decrypts and
+    // matches against the session token. Mirrors AdminMfa's req().
+    if (isset($jar['XSRF-TOKEN'])) $server['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
+    $request = Illuminate\Http\Request::create(BE06_BASE.$uri, $method, $body, $cookies, [], array_replace($server, $extra));
+    $response = $kernel->handle($request);
+    foreach ($response->headers->getCookies() as $c) $jar[$c->getName()] = $c->getValue();
+    $data = json_decode($response->getContent(), true) ?? [];
+    if (isset($data['csrf'])) $jar['_csrf'] = $data['csrf'];
+    $sid = null;
+    if ($request->hasSession()) $sid = $request->session()->getId();
+    $kernel->terminate($request, $response);
+    return ['status' => $response->getStatusCode(), 'data' => $data, 'headers' => $response->headers, 'sid' => $sid];
+}
+
+/** TOTP code at the frozen counter (Google2FA, identical library as AdminTotp). */
+function otp(string $secret, int $offset = 0): string {
+    return (new PragmaRX\Google2FA\Google2FA)->oathTotp($secret, intdiv(now()->timestamp, 30) + $offset);
+}
+
+/** Fresh schema on the disposable SQLite file (same migration list as the MFA harness). */
+function initBe06Schema(): void {
+    global $root;
+    if (DB::getDriverName() !== 'sqlite') throw new RuntimeException('Only the dedicated disposable SQLite file is allowed by default');
+    $db = config('database.connections.sqlite.database');
+    if (!str_contains($db, 'admin-be06-users')) throw new RuntimeException('Wrong disposable test database');
+    DB::statement('PRAGMA busy_timeout=10000');
+    DB::statement('PRAGMA foreign_keys=OFF');
+    foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) {
+        DB::statement('DROP TABLE "'.str_replace('"', '""', $t->name).'"');
+    }
+    DB::statement('PRAGMA foreign_keys=ON');
+    $list = [
+        '2014_10_12_000000_create_users_table.php', '2014_10_12_100000_create_password_reset_tokens_table.php',
+        '2019_12_14_000001_create_personal_access_tokens_table.php', '2026_04_20_125418_create_expenses_table.php',
+        '2026_05_08_211149_create_budgets_table.php', '2026_06_02_232941_add_last_activity_to_users_table.php',
+        '2026_10_02_002051_create_jobs_table.php', '2026_10_06_210747_create_sessions_table.php',
+        '2026_10_27_000001_create_admin_foundations.php', '2026_10_28_000001_create_admin_mfa.php',
+    ];
+    foreach ($list as $f) (require $root.'/database/migrations/'.$f)->up();
+}
+
+function adminLogin(array &$jar): array {
+    req('GET', '/api/admin/csrf', $jar);
+    return req('POST', '/api/admin/login', $jar, ['email' => 'admin@example.test', 'password' => 'fictional-password-12']);
+}
+
+/**
+ * R2 review — sensitive-leak scan over a whole BE-06 JSON payload.
+ * Returns the first forbidden key/sub-sequence found, or null when clean.
+ * Contract keys are already asserted exactly elsewhere; the scan adds a
+ * defense-in-depth guarantee on the raw serialized payload (model
+ * attributes, private factors, finance identifiers, internal reasons).
+ */
+function sensitiveLeak(array $data): ?string {
+    $needles = ['password', 'remember_token', 'suspension_reason', 'last_activity',
+        'email_verified_at', 'totp_ciphertext', 'totp_last_step', 'recovery_code',
+        '"amount"', 'budget', 'expense_id'];
+    $payload = json_encode($data) ?: '';
+    foreach ($needles as $needle) {
+        if (str_contains($payload, $needle)) return $needle;
+    }
+    return null;
+}
+
+/**
+ * R2 review — shared assertions for every BE-06 200 response:
+ * Cache-Control: no-store, private (anti-cache convention of the admin
+ * directory) and the refreshed csrf field, exactly as AdminAuthController
+ * and AdminUserController conventions define them.
+ */
+function be06HttpChecks(array $response, string $label): void {
+    check($label.' cache control', $response['headers']->get('Cache-Control'), 'no-store, private');
+    check($label.' csrf provided', isset($response['data']['csrf']), true);
+}
+
+/* ------------------------------------------------------------------ */
+
+Illuminate\Support\Carbon::setTestNow('2026-11-07 12:00:01 UTC');
+$app = be06App();
+$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
+initBe06Schema();
+
+// Client fixtures (finance isolation is exercised against them).
+$alice = User::create(['name' => 'Alice Toto', 'email' => 'alice@example.test', 'password' => 'fixture-password']);
+$alice->forceFill(['email_verified_at' => now()])->save();
+$bob = User::create(['name' => 'Bob Toto', 'email' => 'bob@example.test', 'password' => 'fixture-password']);
+$bob->forceFill(['email_verified_at' => now()])->save();
+User::create(['name' => 'Carl Dupont', 'email' => 'carl@example.test', 'password' => 'fixture-password'])
+    ->forceFill(['email_verified_at' => now()])->save();
+
+// Super admin identity provisioned through Fériol's service (singleton).
+app(AdminMfa::class)->provision('admin@example.test', 'fictional-password-12');
+check('provisioned identity pending', AdminIdentity::first()->mfa_state, 'pending_enrollment');
+
+// A. csrf bootstrap + admin cookie policy
+$jar = [];
+$r = req('GET', '/api/admin/csrf', $jar);
+check('csrf bootstrap', $r['status'], 200);
+$cookie = $r['headers']->getCookies()[0];
+check('cookie path', $cookie->getPath(), '/api/admin');
+check('cookie secure httponly', $cookie->isSecure() && $cookie->isHttpOnly());
+check('cookie host only', $cookie->getDomain(), null);
+check('cookie strict', $cookie->getSameSite(), 'strict');
+
+// B. CSRF + origin gates (protection CSRF et origine)
+$bare = [];
+check('missing csrf refused', req('POST', '/api/admin/login', $bare)['status'], 419);
+$badcsrf = $jar;
+check('wrong csrf refused', req('POST', '/api/admin/login', $badcsrf, ['email' => 'admin@example.test', 'password' => 'fictional-password-12'], ['HTTP_X_CSRF_TOKEN' => 'wrong'])['status'], 419);
+
+// C. Password gate
+check('wrong password refused', req('POST', '/api/admin/login', $jar, ['email' => 'admin@example.test', 'password' => 'wrong'])['status'], 401);
+check('unknown admin refused', req('POST', '/api/admin/login', $jar, ['email' => 'unknown@example.test', 'password' => 'wrong'])['status'], 401);
+
+// D. Pre-grant refusals: password accepted but no complete MFA grant yet.
+$pre = adminLogin($jar);
+$preSid = $pre['sid'];
+check('login creates challenge', $pre['status'], 200);
+check('pre-grant list refused', req('GET', '/api/admin/users', $jar)['status'], 401);
+check('pre-grant detail refused', req('GET', '/api/admin/users/1', $jar)['status'], 401);
+
+// E. Enrollment (real TOTP factor, Fériol flow)
+$r = req('POST', '/api/admin/enrollment', $jar);
+check('enrollment starts', $r['status'], 200);
+$secret = $r['data']['secret'];
+check('secret length', strlen($secret), 32);
+check('secret not redisclosed', req('POST', '/api/admin/enrollment', $jar)['status'], 409);
+
+// F. Factor acceptance gates (identity budget: 5 per 5 minutes)
+check('wrong totp refused', req('POST', '/api/admin/mfa', $jar, ['otp' => 'xxxxxx'])['status'], 401);
+check('missing otp is 422', req('POST', '/api/admin/mfa', $jar)['status'], 422);
+check('expired totp refused', req('POST', '/api/admin/mfa', $jar, ['otp' => otp($secret, -3)])['status'], 401);
+
+// G. Full MFA success -> durable grant -> authorized access
+$r = req('POST', '/api/admin/mfa', $jar, ['otp' => otp($secret)]);
+check('full MFA success', $r['status'], 200);
+check('session ID rotated', $r['sid'] !== $preSid);
+check('recovery codes delivered once', count($r['data']['recovery_codes'] ?? []), 10);
+check('grants persisted', DB::table('admin_mfa_grants')->count(), 1);
+check('admin session persisted', DB::table('admin_sessions')->where('user_id', 1)->count() >= 1, true);
+
+// H. Happy paths (list + pagination + contract)
+$r = req('GET', '/api/admin/users', $jar);
+check('list authorized', $r['status'], 200);
+check('list rows', count($r['data']['data'] ?? []), 3);
+check('list row contract keys', array_keys($r['data']['data'][0]), ['id', 'nom', 'email', 'created_at']);
+check('list has no name key', in_array('name', array_keys($r['data']['data'][0] ?? [])), false);
+check('list meta total', $r['data']['meta']['total'] ?? null, 3);
+check('list meta current page', $r['data']['meta']['current_page'] ?? null, 1);
+// R2: Cache-Control + csrf on the 200 list response.
+be06HttpChecks($r, 'list');
+// R2: whitelist enforced row by row, then raw payload scan.
+check('list every row contract keys', count(array_filter(
+    $r['data']['data'] ?? [],
+    fn ($row) => array_keys($row) !== ['id', 'nom', 'email', 'created_at']
+)), 0);
+check('list payload clean', sensitiveLeak($r['data']), null);
+
+$r = req('GET', '/api/admin/users?per_page=1', $jar);
+check('per_page respected', count($r['data']['data'] ?? []), 1);
+check('per_page meta', $r['data']['meta']['per_page'] ?? null, 1);
+be06HttpChecks($r, 'per_page');
+// R3: the raw sensitive-leak scan covers every BE-06 200 payload.
+check('per_page payload clean', sensitiveLeak($r['data']), null);
+$firstPageA = $r['data']['data'][0]['id'] ?? null;
+$r2 = req('GET', '/api/admin/users?page=2&per_page=1', $jar);
+check('page 2 meta current page', $r2['data']['meta']['current_page'] ?? null, 2);
+check('page 2 differs from page 1', ($r2['data']['data'][0]['id'] ?? null) !== $firstPageA);
+be06HttpChecks($r2, 'page2');
+check('page2 payload clean', sensitiveLeak($r2['data']), null);
+
+// I. Validation 422
+$r = req('GET', '/api/admin/users?page=0', $jar);
+check('page 0 is 422', $r['status'], 422);
+check('page 0 error key', isset($r['data']['errors']['page']), true);
+check('per_page 51 is 422', req('GET', '/api/admin/users?per_page=51', $jar)['status'], 422);
+check('per_page abc is 422', req('GET', '/api/admin/users?per_page=abc', $jar)['status'], 422);
+
+// J. Search (facultative)
+$r = req('GET', '/api/admin/users?q=toto', $jar);
+check('search toto rows', count($r['data']['data'] ?? []), 2);
+be06HttpChecks($r, 'search toto');
+check('search toto payload clean', sensitiveLeak($r['data']), null);
+$r = req('GET', '/api/admin/users?q=alice', $jar);
+check('search alice single', count($r['data']['data'] ?? []), 1);
+check('search alice matches', ($r['data']['data'][0]['id'] ?? null), $alice->id);
+be06HttpChecks($r, 'search alice');
+check('search alice payload clean', sensitiveLeak($r['data']), null);
+
+// K. Detail + 404
+$r = req('GET', '/api/admin/users/'.$alice->id, $jar);
+check('detail authorized', $r['status'], 200);
+check('detail contract keys', array_keys($r['data']['data'] ?? []), ['id', 'nom', 'email', 'created_at']);
+check('detail nom from name', $r['data']['data']['nom'] ?? null, 'Alice Toto');
+// R2: Cache-Control + csrf on the 200 detail response, plus raw scan.
+be06HttpChecks($r, 'detail');
+check('detail payload clean', sensitiveLeak($r['data']), null);
+$r = req('GET', '/api/admin/users/999999', $jar);
+check('unknown id is 404', $r['status'], 404);
+check('unknown id code', $r['data']['code'] ?? null, 'USER_NOT_FOUND');
+// R2: the 404 answer keeps the admin anti-cache convention. The csrf
+// refresh is only delivered by respond() on 200 payloads (validated BE-06
+// convention); the short-circuit refusal deliberately omits it.
+check('unknown id cache control', $r['headers']->get('Cache-Control'), 'no-store, private');
+check('unknown id no csrf refresh', isset($r['data']['csrf']), false);
+// R3 — explicit checks on the non-numeric id, beyond the bare status.
+// whereNumber('id') keeps /users/abc UNMATCHED by the user directory: the
+// 404 comes from the framework rendering, not from UserController::show()
+// (unlike /users/999999 above, which is a route-matched USER_NOT_FOUND).
+// Assertions therefore stay limited to what any BE-06 refusal guarantees:
+// no user payload, no csrf token refresh, and no sensitive needle anywhere.
+$abc = req('GET', '/api/admin/users/abc', $jar);
+check('non numeric id is 404', $abc['status'], 404);
+check('non numeric id carries no user data',
+    array_key_exists('data', $abc['data']) || isset($abc['data']['nom']) || isset($abc['data']['email']),
+    false);
+check('non numeric id no csrf refresh', isset($abc['data']['csrf']), false);
+check('non numeric id payload clean', sensitiveLeak($abc['data']), null);
+
+// L. Origin / bearer / client isolation
+$foreign = $jar;
+check('foreign origin refused', req('GET', '/api/admin/users', $foreign, [], ['HTTP_ORIGIN' => 'https://other.test'])['status'], 403);
+$noAuth = [];
+check('bearer refused', req('GET', '/api/admin/csrf', $noAuth, [], ['HTTP_AUTHORIZATION' => 'Bearer fictional'])['status'], 401);
+check('admin cannot read client finance', req('GET', '/api/expenses', $jar)['status'], 401);
+
+// M. Client side must stay untouched by the admin flows.
+$client = [];
+req('GET', '/sanctum/csrf-cookie', $client);
+check('client login preserved', req('POST', '/api/login', $client, ['email' => 'alice@example.test', 'password' => 'fixture-password'])['status'], 200);
+check('client me works', req('GET', '/api/user', $client)['status'], 200);
+check('client cannot enter admin', req('GET', '/api/admin/users', $client)['status'], 401);
+
+// N. Route surface: only auth endpoints + the two BE-06 reads
+$app = be06App();
+// A raw be06App() has never bootstrapped, so the RouteServiceProvider has
+// not registered anything yet and getRoutes() returns an empty list. Boot
+// the Console Kernel exactly like the schema-init block above does. No
+// double-bootstrap risk: this is a FRESH application instance, booted once,
+// never handled by the HTTP kernel.
+$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
+$allowed = [
+    'api/admin/csrf', 'api/admin/login', 'api/admin/enrollment', 'api/admin/mfa', 'api/admin/recovery',
+    'api/admin/logout', 'api/admin/session', 'api/admin/reauthenticate', 'api/admin/factor/change',
+    'api/admin/users', 'api/admin/users/{id}',
+];
+$actual = array_values(array_filter(array_map(
+    fn ($r) => $r->uri(),
+    iterator_to_array($app['router']->getRoutes())
+), fn (string $uri) => str_starts_with($uri, 'api/admin')));
+sort($allowed); sort($actual);
+check('admin route surface exact', $actual, $allowed);
+
+// O. Security events payload hygiene
+check('mfaAuthenticated event recorded', DB::table('admin_security_events')->where('event', 'mfa_authenticated')->count() >= 1, true);
+check('event payload minimal', array_keys((array) DB::table('admin_security_events')->first()), ['id', 'event', 'admin_id', 'created_at']);
+
+// Evidence + exit code
+$failed = count(array_filter($rows, fn ($r) => $r['status'] === 'FAIL'));
+$file = dirname(__DIR__, 2).'/storage/app/admin-be06-users/runtime/evidence/admin-users-http-sqlite.json';
+file_put_contents($file, json_encode([
+    'harness' => 'BE-06 admin users HTTP/MFA',
+    'base' => 'feature/fm-admin-be-06-users-api @ 9d70a34',
+    'engine' => 'sqlite',
+    'passed' => count($rows) - $failed,
+    'failed' => $failed,
+    'checks' => $rows,
+], JSON_PRETTY_PRINT));
+echo 'TOTAL '.count($rows).' FAILED '.$failed."\n";
+echo "Evidence: {$file}\n";
+exit($failed ? 1 : 0);
```

## Conventions

- --no-index is used for UNTRACKED files: the Git index state was never altered.
- Nothing committed, pushed or merged: the whole BE-06 set stays uncommitted in the gestionnaire-backend-be-06 worktree.
- The tracked routes/admin.php diff is taken against the PR base 9d70a34d5c1ce4c5a17b94f6c36ca6cfde999003.
