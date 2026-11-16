<?php

/*
 * BE-06 — standalone HTTP/MFA integration harness for the read-only admin
 * user directory (GET /api/admin/users, GET /api/admin/users/{id}).
 *
 * OPS-02 GATE — READ THIS BEFORE RUNNING:
 * Running this script touches a real (disposable) database. It must only be
 * executed AFTER the OPS-02 test isolation has been qualified and validated:
 *
 *     php tests/AdminUsersHttp/run.php run
 *
 * Without the explicit 'run' argument the script performs NOTHING: it
 * prints SKIPPED and exits 0 without connecting to any database.
 *
 * Scenario coverage (contract BE-06):
 *   1. Routes gated behind the full admin chain (AdminMfaEnabled,
 *      SelectAdminContext, AdminCsrf, RequireAdminIdentity, RequireAdminMfa).
 *   2. Refusal without a valid MFA grant (pre-grant 401).
 *   3. Authorized access with a real admin session (csrf -> login ->
 *      enrollment -> TOTP verify, identical to the Fériol MFA flow).
 *   4. Paginated list (default page=1/per_page=15; per_page up to 50).
 *   5. Facultative search q (columns name/email, jokers escaped).
 *   6. 404 payload USER_NOT_FOUND for unknown and non-numeric ids.
 *   7. 422 validation for invalid page/per_page.
 *   8. CSRF token refusals (419) and origin/ bearer refusals (403/401).
 *   9. Client isolation: admin session cannot reach finance endpoints,
 *      client session cannot enter /api/admin, admin session cannot enter
 *      /api/user, and no finance field leaks in the user payloads.
 *  10. R2 review additions: Cache-Control: no-store, private plus refreshed
 *      csrf on every BE-06 200 response, anti-cache header kept on the 404
 *      refusal (csrf intentionally absent there), whitelisting swept row
 *      by row, and a raw sensitive-leak scan on every BE-06 payload.
 *  11. R3 review additions: the sensitive-leak scan is applied to ALL the
 *      BE-06 200 payloads (list, per_page, page 2, both searches, detail)
 *      and the non-numeric /users/abc refusal asserts much more than its
 *      status (no user payload, no csrf refresh, clean scanned body).
 */

if (($argv[1] ?? '') !== 'run') {
    echo "SKIPPED — BE-06 admin users HTTP/MFA harness. Requires an OPS-02-qualified environment; execution command: php tests/AdminUsersHttp/run.php run\n";
    exit(0);
}

require __DIR__.'/bootstrap.php';
use App\Models\AdminIdentity;
use App\Models\User;
use App\Services\Admin\AdminMfa;
use Illuminate\Support\Facades\DB;

echo "BE-06 harness — AdminIdentity, AdminMfa and middlewares by Fériol; BE-06 endpoints under review.\n";
echo "Policy: execution allowed ONLY on the dedicated disposable SQLite file.\n";

define('BE06_BASE', 'https://client.foundation.test');
$rows = [];

/** Mirror of the AdminMfa harness check(). */
function check(string $name, $actual, $expected = true): void {
    global $rows;
    $pass = $actual === $expected;
    $rows[] = ['name' => $name, 'status' => $pass ? 'PASS' : 'FAIL', 'actual' => $actual, 'expected' => $expected];
    echo ($pass ? 'PASS ' : 'FAIL ').$name.($pass ? '' : ' [' . json_encode($actual) . ' != ' . json_encode($expected) . ']')."\n";
}

/** Mirror of req(): a fresh app per request, manual cookie jar, admin context. */
function req(string $method, string $uri, array &$jar, array $body = [], array $extra = []): array {
    $app = be06App();
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $cookies = $jar; unset($cookies['_csrf']);
    $server = [
        'HTTPS' => 'on', 'SERVER_PORT' => 443,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ORIGIN' => BE06_BASE, 'HTTP_REFERER' => BE06_BASE.'/',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    if (isset($jar['_csrf'])) $server['HTTP_X_CSRF_TOKEN'] = $jar['_csrf'];
    // Sanctum client convention: the stateful SPA pipeline (EnsureFrontend-
    // RequestsAreStateful -> config('sanctum.middleware.validate_csrf_token')
    // = PreventRequestForgery, AUTH3-01) expects the ENCRYPTED csrf cookie
    // value echoed in the X-XSRF-TOKEN header, which Laravel decrypts and
    // matches against the session token. Mirrors AdminMfa's req().
    if (isset($jar['XSRF-TOKEN'])) $server['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
    $request = Illuminate\Http\Request::create(BE06_BASE.$uri, $method, $body, $cookies, [], array_replace($server, $extra));
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $c) $jar[$c->getName()] = $c->getValue();
    $data = json_decode($response->getContent(), true) ?? [];
    if (isset($data['csrf'])) $jar['_csrf'] = $data['csrf'];
    $sid = null;
    if ($request->hasSession()) $sid = $request->session()->getId();
    $kernel->terminate($request, $response);
    return ['status' => $response->getStatusCode(), 'data' => $data, 'headers' => $response->headers, 'sid' => $sid];
}

/** TOTP code at the frozen counter (Google2FA, identical library as AdminTotp). */
function otp(string $secret, int $offset = 0): string {
    return (new PragmaRX\Google2FA\Google2FA)->oathTotp($secret, intdiv(now()->timestamp, 30) + $offset);
}

/** Fresh schema on the disposable SQLite file (same migration list as the MFA harness). */
function initBe06Schema(): void {
    global $root;
    if (DB::getDriverName() !== 'sqlite') throw new RuntimeException('Only the dedicated disposable SQLite file is allowed by default');
    $db = config('database.connections.sqlite.database');
    if (!str_contains($db, 'admin-be06-users')) throw new RuntimeException('Wrong disposable test database');
    DB::statement('PRAGMA busy_timeout=10000');
    DB::statement('PRAGMA foreign_keys=OFF');
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) {
        DB::statement('DROP TABLE "'.str_replace('"', '""', $t->name).'"');
    }
    DB::statement('PRAGMA foreign_keys=ON');
    $list = [
        '2014_10_12_000000_create_users_table.php', '2014_10_12_100000_create_password_reset_tokens_table.php',
        '2019_12_14_000001_create_personal_access_tokens_table.php', '2026_04_20_125418_create_expenses_table.php',
        '2026_05_08_211149_create_budgets_table.php', '2026_06_02_232941_add_last_activity_to_users_table.php',
        '2026_10_02_002051_create_jobs_table.php', '2026_10_06_210747_create_sessions_table.php',
        '2026_10_27_000001_create_admin_foundations.php', '2026_10_28_000001_create_admin_mfa.php',
    ];
    foreach ($list as $f) (require $root.'/database/migrations/'.$f)->up();
}

function adminLogin(array &$jar): array {
    req('GET', '/api/admin/csrf', $jar);
    return req('POST', '/api/admin/login', $jar, ['email' => 'admin@example.test', 'password' => 'fictional-password-12']);
}

/**
 * R2 review — sensitive-leak scan over a whole BE-06 JSON payload.
 * Returns the first forbidden key/sub-sequence found, or null when clean.
 * Contract keys are already asserted exactly elsewhere; the scan adds a
 * defense-in-depth guarantee on the raw serialized payload (model
 * attributes, private factors, finance identifiers, internal reasons).
 */
function sensitiveLeak(array $data): ?string {
    $needles = ['password', 'remember_token', 'suspension_reason', 'last_activity',
        'email_verified_at', 'totp_ciphertext', 'totp_last_step', 'recovery_code',
        '"amount"', 'budget', 'expense_id'];
    $payload = json_encode($data) ?: '';
    foreach ($needles as $needle) {
        if (str_contains($payload, $needle)) return $needle;
    }
    return null;
}

/**
 * R2 review — shared assertions for every BE-06 200 response:
 * Cache-Control: no-store, private (anti-cache convention of the admin
 * directory) and the refreshed csrf field, exactly as AdminAuthController
 * and AdminUserController conventions define them.
 */
function be06HttpChecks(array $response, string $label): void {
    check($label.' cache control', $response['headers']->get('Cache-Control'), 'no-store, private');
    check($label.' csrf provided', isset($response['data']['csrf']), true);
}

/* ------------------------------------------------------------------ */

Illuminate\Support\Carbon::setTestNow('2026-11-07 12:00:01 UTC');
$app = be06App();
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
initBe06Schema();

// Client fixtures (finance isolation is exercised against them).
$alice = User::create(['name' => 'Alice Toto', 'email' => 'alice@example.test', 'password' => 'fixture-password']);
$alice->forceFill(['email_verified_at' => now()])->save();
$bob = User::create(['name' => 'Bob Toto', 'email' => 'bob@example.test', 'password' => 'fixture-password']);
$bob->forceFill(['email_verified_at' => now()])->save();
User::create(['name' => 'Carl Dupont', 'email' => 'carl@example.test', 'password' => 'fixture-password'])
    ->forceFill(['email_verified_at' => now()])->save();

// Super admin identity provisioned through Fériol's service (singleton).
app(AdminMfa::class)->provision('admin@example.test', 'fictional-password-12');
check('provisioned identity pending', AdminIdentity::first()->mfa_state, 'pending_enrollment');

// A. csrf bootstrap + admin cookie policy
$jar = [];
$r = req('GET', '/api/admin/csrf', $jar);
check('csrf bootstrap', $r['status'], 200);
$cookie = $r['headers']->getCookies()[0];
check('cookie path', $cookie->getPath(), '/api/admin');
check('cookie secure httponly', $cookie->isSecure() && $cookie->isHttpOnly());
check('cookie host only', $cookie->getDomain(), null);
check('cookie strict', $cookie->getSameSite(), 'strict');

// B. CSRF + origin gates (protection CSRF et origine)
$bare = [];
check('missing csrf refused', req('POST', '/api/admin/login', $bare)['status'], 419);
$badcsrf = $jar;
check('wrong csrf refused', req('POST', '/api/admin/login', $badcsrf, ['email' => 'admin@example.test', 'password' => 'fictional-password-12'], ['HTTP_X_CSRF_TOKEN' => 'wrong'])['status'], 419);

// C. Password gate
check('wrong password refused', req('POST', '/api/admin/login', $jar, ['email' => 'admin@example.test', 'password' => 'wrong'])['status'], 401);
check('unknown admin refused', req('POST', '/api/admin/login', $jar, ['email' => 'unknown@example.test', 'password' => 'wrong'])['status'], 401);

// D. Pre-grant refusals: password accepted but no complete MFA grant yet.
$pre = adminLogin($jar);
$preSid = $pre['sid'];
check('login creates challenge', $pre['status'], 200);
check('pre-grant list refused', req('GET', '/api/admin/users', $jar)['status'], 401);
check('pre-grant detail refused', req('GET', '/api/admin/users/1', $jar)['status'], 401);

// E. Enrollment (real TOTP factor, Fériol flow)
$r = req('POST', '/api/admin/enrollment', $jar);
check('enrollment starts', $r['status'], 200);
$secret = $r['data']['secret'];
check('secret length', strlen($secret), 32);
check('secret not redisclosed', req('POST', '/api/admin/enrollment', $jar)['status'], 409);

// F. Factor acceptance gates (identity budget: 5 per 5 minutes)
check('wrong totp refused', req('POST', '/api/admin/mfa', $jar, ['otp' => 'xxxxxx'])['status'], 401);
check('missing otp is 422', req('POST', '/api/admin/mfa', $jar)['status'], 422);
check('expired totp refused', req('POST', '/api/admin/mfa', $jar, ['otp' => otp($secret, -3)])['status'], 401);

// G. Full MFA success -> durable grant -> authorized access
$r = req('POST', '/api/admin/mfa', $jar, ['otp' => otp($secret)]);
check('full MFA success', $r['status'], 200);
check('session ID rotated', $r['sid'] !== $preSid);
check('recovery codes delivered once', count($r['data']['recovery_codes'] ?? []), 10);
check('grants persisted', DB::table('admin_mfa_grants')->count(), 1);
check('admin session persisted', DB::table('admin_sessions')->where('user_id', 1)->count() >= 1, true);

// H. Happy paths (list + pagination + contract)
$r = req('GET', '/api/admin/users', $jar);
check('list authorized', $r['status'], 200);
check('list rows', count($r['data']['data'] ?? []), 3);
check('list row contract keys', array_keys($r['data']['data'][0]), ['id', 'nom', 'email', 'created_at']);
check('list has no name key', in_array('name', array_keys($r['data']['data'][0] ?? [])), false);
check('list meta total', $r['data']['meta']['total'] ?? null, 3);
check('list meta current page', $r['data']['meta']['current_page'] ?? null, 1);
// R2: Cache-Control + csrf on the 200 list response.
be06HttpChecks($r, 'list');
// R2: whitelist enforced row by row, then raw payload scan.
check('list every row contract keys', count(array_filter(
    $r['data']['data'] ?? [],
    fn ($row) => array_keys($row) !== ['id', 'nom', 'email', 'created_at']
)), 0);
check('list payload clean', sensitiveLeak($r['data']), null);

$r = req('GET', '/api/admin/users?per_page=1', $jar);
check('per_page respected', count($r['data']['data'] ?? []), 1);
check('per_page meta', $r['data']['meta']['per_page'] ?? null, 1);
be06HttpChecks($r, 'per_page');
// R3: the raw sensitive-leak scan covers every BE-06 200 payload.
check('per_page payload clean', sensitiveLeak($r['data']), null);
$firstPageA = $r['data']['data'][0]['id'] ?? null;
$r2 = req('GET', '/api/admin/users?page=2&per_page=1', $jar);
check('page 2 meta current page', $r2['data']['meta']['current_page'] ?? null, 2);
check('page 2 differs from page 1', ($r2['data']['data'][0]['id'] ?? null) !== $firstPageA);
be06HttpChecks($r2, 'page2');
check('page2 payload clean', sensitiveLeak($r2['data']), null);

// I. Validation 422
$r = req('GET', '/api/admin/users?page=0', $jar);
check('page 0 is 422', $r['status'], 422);
check('page 0 error key', isset($r['data']['errors']['page']), true);
check('per_page 51 is 422', req('GET', '/api/admin/users?per_page=51', $jar)['status'], 422);
check('per_page abc is 422', req('GET', '/api/admin/users?per_page=abc', $jar)['status'], 422);

// J. Search (facultative)
$r = req('GET', '/api/admin/users?q=toto', $jar);
check('search toto rows', count($r['data']['data'] ?? []), 2);
be06HttpChecks($r, 'search toto');
check('search toto payload clean', sensitiveLeak($r['data']), null);
$r = req('GET', '/api/admin/users?q=alice', $jar);
check('search alice single', count($r['data']['data'] ?? []), 1);
check('search alice matches', ($r['data']['data'][0]['id'] ?? null), $alice->id);
be06HttpChecks($r, 'search alice');
check('search alice payload clean', sensitiveLeak($r['data']), null);

// K. Detail + 404
$r = req('GET', '/api/admin/users/'.$alice->id, $jar);
check('detail authorized', $r['status'], 200);
check('detail contract keys', array_keys($r['data']['data'] ?? []), ['id', 'nom', 'email', 'created_at']);
check('detail nom from name', $r['data']['data']['nom'] ?? null, 'Alice Toto');
// R2: Cache-Control + csrf on the 200 detail response, plus raw scan.
be06HttpChecks($r, 'detail');
check('detail payload clean', sensitiveLeak($r['data']), null);
$r = req('GET', '/api/admin/users/999999', $jar);
check('unknown id is 404', $r['status'], 404);
check('unknown id code', $r['data']['code'] ?? null, 'USER_NOT_FOUND');
// R2: the 404 answer keeps the admin anti-cache convention. The csrf
// refresh is only delivered by respond() on 200 payloads (validated BE-06
// convention); the short-circuit refusal deliberately omits it.
check('unknown id cache control', $r['headers']->get('Cache-Control'), 'no-store, private');
check('unknown id no csrf refresh', isset($r['data']['csrf']), false);
// R3 — explicit checks on the non-numeric id, beyond the bare status.
// whereNumber('id') keeps /users/abc UNMATCHED by the user directory: the
// 404 comes from the framework rendering, not from UserController::show()
// (unlike /users/999999 above, which is a route-matched USER_NOT_FOUND).
// Assertions therefore stay limited to what any BE-06 refusal guarantees:
// no user payload, no csrf token refresh, and no sensitive needle anywhere.
$abc = req('GET', '/api/admin/users/abc', $jar);
check('non numeric id is 404', $abc['status'], 404);
check('non numeric id carries no user data',
    array_key_exists('data', $abc['data']) || isset($abc['data']['nom']) || isset($abc['data']['email']),
    false);
check('non numeric id no csrf refresh', isset($abc['data']['csrf']), false);
check('non numeric id payload clean', sensitiveLeak($abc['data']), null);

// L. Origin / bearer / client isolation
$foreign = $jar;
check('foreign origin refused', req('GET', '/api/admin/users', $foreign, [], ['HTTP_ORIGIN' => 'https://other.test'])['status'], 403);
$noAuth = [];
check('bearer refused', req('GET', '/api/admin/csrf', $noAuth, [], ['HTTP_AUTHORIZATION' => 'Bearer fictional'])['status'], 401);
check('admin cannot read client finance', req('GET', '/api/expenses', $jar)['status'], 401);

// M. Client side must stay untouched by the admin flows.
$client = [];
req('GET', '/sanctum/csrf-cookie', $client);
check('client login preserved', req('POST', '/api/login', $client, ['email' => 'alice@example.test', 'password' => 'fixture-password'])['status'], 200);
check('client me works', req('GET', '/api/user', $client)['status'], 200);
check('client cannot enter admin', req('GET', '/api/admin/users', $client)['status'], 401);

// N. Route surface: only auth endpoints + the two BE-06 reads
$app = be06App();
// A raw be06App() has never bootstrapped, so the RouteServiceProvider has
// not registered anything yet and getRoutes() returns an empty list. Boot
// the Console Kernel exactly like the schema-init block above does. No
// double-bootstrap risk: this is a FRESH application instance, booted once,
// never handled by the HTTP kernel.
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$allowed = [
    'api/admin/csrf', 'api/admin/login', 'api/admin/enrollment', 'api/admin/mfa', 'api/admin/recovery',
    'api/admin/logout', 'api/admin/session', 'api/admin/reauthenticate', 'api/admin/factor/change',
    'api/admin/users', 'api/admin/users/{id}',
];
$actual = array_values(array_filter(array_map(
    fn ($r) => $r->uri(),
    iterator_to_array($app['router']->getRoutes())
), fn (string $uri) => str_starts_with($uri, 'api/admin')));
sort($allowed); sort($actual);
check('admin route surface exact', $actual, $allowed);

// O. Security events payload hygiene
check('mfaAuthenticated event recorded', DB::table('admin_security_events')->where('event', 'mfa_authenticated')->count() >= 1, true);
check('event payload minimal', array_keys((array) DB::table('admin_security_events')->first()), ['id', 'event', 'admin_id', 'created_at']);

// Evidence + exit code
$failed = count(array_filter($rows, fn ($r) => $r['status'] === 'FAIL'));
$file = dirname(__DIR__, 2).'/storage/app/admin-be06-users/runtime/evidence/admin-users-http-sqlite.json';
file_put_contents($file, json_encode([
    'harness' => 'BE-06 admin users HTTP/MFA',
    'base' => 'feature/fm-admin-be-06-users-api @ 9d70a34',
    'engine' => 'sqlite',
    'passed' => count($rows) - $failed,
    'failed' => $failed,
    'checks' => $rows,
], JSON_PRETTY_PRINT));
echo 'TOTAL '.count($rows).' FAILED '.$failed."\n";
echo "Evidence: {$file}\n";
exit($failed ? 1 : 0);
