<?php

/*
 * BE-03 — banc de bout en bout : suspension / réactivation administratives.
 *
 *   php tests/AdminSuspension/run.php run
 *
 * Variables obligatoires (instance SQL jetable, voir lib.php) :
 *   BE03_DB_HOST, BE03_DB_PORT, BE03_DB_DATABASE (…_test), BE03_DB_USERNAME,
 *   BE03_DB_EXPECTED_DATADIR ; facultatives : BE03_DB_PASSWORD, BE03_DB_DRIVER.
 *
 * Sans l'argument « run », ne fait RIEN (SKIPPED, code 0).
 * Couvre T01–T17 du contrat BE-03 (T13 : volet BE-03 seulement) et les
 * scénarios SEC-01 S03–S13, S16, S17, S20.
 */

if (($argv[1] ?? '') !== 'run') {
    echo "SKIPPED — BE-03 suspension harness. Requires a disposable SQL instance; command: php tests/AdminSuspension/run.php run\n";
    exit(0);
}

// Toute sortie avant le bilan final (garde d'isolation, exception rendue par le
// gestionnaire Laravel…) doit produire un code non nul, jamais un faux succès.
$GLOBALS['be03Completed'] = false;
register_shutdown_function(function () {
    if (! $GLOBALS['be03Completed']) {
        echo "\nINCOMPLETE — harness aborted before its final summary.\n";
        exit(2);
    }
});

require __DIR__.'/lib.php';

use App\Services\Admin\AdminMfa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

$results = [];
function check(string $name, $actual, $expected = true): void
{
    global $results;
    $pass = $actual === $expected;
    $results[] = ['name' => $name, 'pass' => $pass, 'actual' => $pass ? null : $actual, 'expected' => $pass ? null : $expected];
    echo ($pass ? 'PASS ' : 'FAIL ').$name.($pass ? '' : ' ['.json_encode($actual).' != '.json_encode($expected).']')."\n";
}

/** Relit l'état durable d'un client (nouvelle application, rien en mémoire). */
function state(int $id): array
{
    be03Console();
    $u = DB::table('users')->where('id', $id)->first();

    return [
        'status' => $u->administrative_status, 'version' => (int) $u->auth_version,
        'suspended_at' => $u->suspended_at, 'suspended_by' => $u->suspended_by === null ? null : (int) $u->suspended_by,
        'pats' => DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->where('tokenable_id', $id)->count(),
        'sessions' => DB::table('sessions')->where('user_id', $id)->count(),
        'audit_ok' => DB::table('admin_audit_events')->where('target_user_id', $id)->where('outcome', 'succeeded')->count(),
        'audit_refused' => DB::table('admin_audit_events')->where('target_user_id', $id)->where('outcome', 'refused_redundant')->count(),
        'outbox' => DB::table('notification_outbox')->where('recipient_user_id', $id)->count(),
    ];
}

function financeFingerprint(): string
{
    be03Console();

    return sha1(json_encode([
        DB::table('expenses')->orderBy('id')->get(['id', 'user_id', 'description', 'amount', 'category', 'date'])->all(),
        DB::table('budgets')->orderBy('id')->get(['id', 'user_id', 'month', 'amount'])->all(),
        DB::table('users')->count(),
    ]));
}

function body(string $email, string $reason = 'Motif administratif fictif BE-03'): array
{
    return ['reason' => $reason, 'confirm_email' => $email];
}

/** Lance un worker indépendant ; renvoie [process, dossier]. */
function spawn(string $dir, string $slot, array $input)
{
    file_put_contents($dir.'/input-'.$slot.'.json', json_encode($input + ['now' => now()->toIso8601String()]));
    $p = proc_open([PHP_BINARY, __DIR__.'/worker.php', $dir, $slot], [0 => ['pipe', 'r'], 1 => ['file', $dir.'/out-'.$slot.'.txt', 'w'], 2 => ['file', $dir.'/err-'.$slot.'.txt', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($p)) {
        throw new RuntimeException('Cannot start worker');
    }
    fclose($pipes[0]);

    return $p;
}

function raceDir(string $name): string
{
    global $runtime;
    $dir = $runtime.'/race/'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

function waitFor(string $file, float $timeout = 30): void
{
    $until = microtime(true) + $timeout;
    while (! is_file($file)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Timeout waiting for '.basename($file));
        }
        usleep(5000);
    }
}

/** Le worker A tient ses verrous (hold) ; $main s'exécute pendant ce temps. */
function holdRace(string $name, array $inputA, callable $main): array
{
    $dir = raceDir($name);
    $p = spawn($dir, 'a', $inputA);
    waitFor($dir.'/locked-a');
    $mainResult = $main();
    if (proc_close($p) !== 0) {
        throw new RuntimeException('Worker failed: '.@file_get_contents($dir.'/err-a.txt'));
    }

    return [json_decode(file_get_contents($dir.'/result-a.json'), true), $mainResult];
}

/** Deux workers relâchés ensemble par une barrière commune. */
function barrierRace(string $name, array $inputA, array $inputB): array
{
    $dir = raceDir($name);
    $pa = spawn($dir, 'a', $inputA + ['wait_go' => true]);
    $pb = spawn($dir, 'b', $inputB + ['wait_go' => true]);
    waitFor($dir.'/ready-a');
    waitFor($dir.'/ready-b');
    touch($dir.'/go');
    foreach ([$pa, $pb] as $p) {
        if (proc_close($p) !== 0) {
            throw new RuntimeException('Worker failed');
        }
    }

    return [json_decode(file_get_contents($dir.'/result-a.json'), true), json_decode(file_get_contents($dir.'/result-b.json'), true)];
}

const HOLD = 2.0;
$REASON = 'Motif administratif fictif BE-03';

$startedAt = date(DATE_ATOM);
Carbon::setTestNow(Carbon::now()->startOfSecond());

// =========================================================== T17 — migration
be03Console();
$iso = be03AssertIsolated();
echo 'Engine: '.$iso['version'].' port '.$iso['port'].' database '.$iso['database']."\n";
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);
be03Console();
check('T17 pre-BE-03 schema has no status column', DB::getSchemaBuilder()->hasColumn('users', 'administrative_status'), false);

// Clients existants AVANT la migration BE-03 (ids 1..4 ; l'id 1 collisionne avec l'admin singleton).
$hash = Hash::make(BE03_CLIENT_PASSWORD);
$clients = [];
foreach (['client.a', 'client.b', 'client.c', 'client.d'] as $i => $local) {
    $clients[$local] = DB::table('users')->insertGetId([
        'name' => 'Client fictif '.($i + 1), 'email' => $local.'@example.test', 'password' => $hash,
        'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}
[$A, $B, $C, $D] = array_values($clients);
foreach ([$A, $B, $C] as $owner) {
    DB::table('expenses')->insert(['user_id' => $owner, 'description' => 'Dépense fictive', 'amount' => 1234.5, 'category' => 'Transport', 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('budgets')->insert(['user_id' => $owner, 'month' => now()->startOfMonth()->toDateString(), 'amount' => 50000, 'created_at' => now(), 'updated_at' => now()]);
}
$fingerprint = financeFingerprint();

Artisan::call('migrate', ['--force' => true]);
be03Console();
$rows = DB::table('users')->orderBy('id')->get();
check('T17 existing clients migrated active/v1', $rows->every(fn ($r) => $r->administrative_status === 'active' && (int) $r->auth_version === 1 && $r->suspended_at === null && $r->suspended_by === null));
check('T17 finances untouched by migration', financeFingerprint(), $fingerprint);
try {
    DB::table('users')->where('id', $D)->update(['administrative_status' => 'banned']);
    check('T17 CHECK constraint rejects unknown status', false);
} catch (Illuminate\Database\QueryException) {
    check('T17 CHECK constraint rejects unknown status', true);
}
Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]);
be03Console();
check('T17 down() removes BE-03 schema', [DB::getSchemaBuilder()->hasColumn('users', 'auth_version'), DB::getSchemaBuilder()->hasTable('admin_audit_events'), DB::getSchemaBuilder()->hasTable('notification_outbox')], [false, false, false]);
check('T17 rollback keeps finances', financeFingerprint(), $fingerprint);
Artisan::call('migrate', ['--force' => true]);
be03Console();
check('T17 re-migration idempotent', DB::table('users')->where('administrative_status', 'active')->count(), 4);

// ============================================================ admin + clients
be03Console();
app(AdminMfa::class)->provision(BE03_ADMIN_EMAIL, BE03_ADMIN_PASSWORD);
$secret = null;
$adm = [];
be03AdminSession($adm, $secret);

$sessFile = [];
$sessDb = [];
check('setup client A file login', be03ClientLogin($sessFile, 'client.a@example.test', 'file')['status'], 200);
check('setup client A database login', be03ClientLogin($sessDb, 'client.a@example.test', 'database')['status'], 200);
be03Console();
$patA = App\Models\User::find($A)->createToken('legacy-a')->plainTextToken;
$patB = App\Models\User::find($B)->createToken('legacy-b')->plainTextToken;
check('setup A file session works', be03Client('GET', '/api/user', $sessFile)['status'], 200);
check('setup A database session works', be03Client('GET', '/api/user', $sessDb, [], 'database')['status'], 200);
check('setup A PAT works', be03Bearer($patA, 'GET', '/api/expenses')['status'], 200);
check('setup A has a database session row', state($A)['sessions'], 1);

// =========================================================== T01 — sans MFA
$anon = [];
be03Req('admin', 'GET', '/api/admin/csrf', $anon);
check('T01 anonymous admin refused', be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $anon, body('client.a@example.test'))['status'], 401);
$pre = [];
be03Req('admin', 'GET', '/api/admin/csrf', $pre);
be03Req('admin', 'POST', '/api/admin/login', $pre, ['email' => BE03_ADMIN_EMAIL, 'password' => BE03_ADMIN_PASSWORD]);
check('T01 pre-MFA challenge session refused', be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $pre, body('client.a@example.test'))['status'], 401);
$r = be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $adm, body('client.a@example.test'), ['HTTP_AUTHORIZATION' => 'Bearer '.$patA]);
check('T01 bearer refused on admin', [$r['status'], $r['data']['code'] ?? null], [401, 'ADMIN_BEARER_REFUSED']);
$r = be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $adm, body('client.a@example.test'), ['HTTP_ORIGIN' => BE03_CLIENT]);
check('T01 foreign origin refused', [$r['status'], $r['data']['code'] ?? null], [403, 'ADMIN_ORIGIN_REFUSED']);
$noCsrf = $adm;
unset($noCsrf['_csrf']);
check('T01 missing CSRF refused (419)', be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $noCsrf, body('client.a@example.test'))['status'], 419);
check('T01 nothing changed', [state($A)['status'], state($A)['version']], ['active', 1]);

// ======================================================== T02 — MFA récente
// La vérification TOTP du login pose authenticated_at (sémantique BE-02) :
// on vieillit cette preuve au-delà de 300 s, grant toujours valide (< 900 s).
Carbon::setTestNow(now()->addSeconds(301));
$r = be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $adm, body('client.a@example.test'));
check('T02 valid grant but MFA proof older than 300 s refused', [$r['status'], $r['data']['message'] ?? null], [403, 'ADMIN_RECENT_AUTH_REQUIRED']);
check('T02 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
Carbon::setTestNow(now()->addSeconds(301));
$r = be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $adm, body('client.a@example.test'));
check('T02 reauth older than 300 s refused', [$r['status'], $r['data']['message'] ?? null], [403, 'ADMIN_RECENT_AUTH_REQUIRED']);
check('T02 nothing changed', [state($A)['status'], state($A)['version'], state($A)['audit_ok'] + state($A)['audit_refused']], ['active', 1, 0]);

// ============================================== T03 — validation / 404
check('T03 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
$cases = [
    'empty reason' => ['reason' => '   ', 'confirm_email' => 'client.a@example.test'],
    'reason > 2000' => ['reason' => str_repeat('x', 2001), 'confirm_email' => 'client.a@example.test'],
    'missing confirm' => ['reason' => $REASON],
    'confirm other case' => body('Client.A@example.test'),
    'confirm other account' => body('client.b@example.test'),
];
foreach ($cases as $label => $payload) {
    $r = be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $adm, $payload);
    check("T03 $label ⇒ 422", $r['status'], 422);
    check("T03 $label ⇒ no echo of submitted values", str_contains($r['raw'], 'client.b@example.test') || str_contains($r['raw'], 'Client.A'), false);
}
$r = be03Req('admin', 'POST', '/api/admin/users/999999/suspend', $adm, body('nobody@example.test'));
check('T03 unknown user ⇒ 404 USER_NOT_FOUND', [$r['status'], $r['data']['code'] ?? null], [404, 'USER_NOT_FOUND']);
// F-05 / addenda GOV-05 22/11/2026 : erreurs admin = {message, code}.
check('F-05 BE-03 404 body is exactly {message, code}', [array_keys($r['data']), is_string($r['data']['message'] ?? null) && $r['data']['message'] !== ''], [['message', 'code'], true]);
check('F-05 BE-03 404 does not echo the submitted e-mail', str_contains($r['raw'], 'nobody@example.test'), false);
$r6 = be03Req('admin', 'GET', '/api/admin/users/999999', $adm);
check('F-05 BE-06 404 body', [$r6['status'], $r6['data']], [404, ['message' => 'Utilisateur introuvable', 'code' => 'USER_NOT_FOUND']]);
check('F-05 BE-06 404 no-store', $r6['headers']->get('Cache-Control'), 'no-store, private');
$anon6 = [];
be03Req('admin', 'GET', '/api/admin/csrf', $anon6);
$r6a = be03Req('admin', 'GET', '/api/admin/users/999999', $anon6);
check('F-05 BE-06 detail still requires the admin MFA grant (no 404 oracle)', [$r6a['status'], array_key_exists('code', $r6a['data']) && $r6a['data']['code'] === 'USER_NOT_FOUND'], [401, false]);
check('T03 non-numeric id ⇒ 404', be03Req('admin', 'POST', '/api/admin/users/abc/suspend', $adm, body('client.a@example.test'))['status'], 404);
check('T03 nothing changed', [state($A)['status'], state($A)['version']], ['active', 1]);

// ============================================== T04 — suspension (240 s)
Carbon::setTestNow(now()->addSeconds(240 - 30));
check('T04 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
Carbon::setTestNow(now()->addSeconds(240));
$r = be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $adm, body('client.a@example.test'));
check('T04 suspension 240 s after reauth ⇒ 200', $r['status'], 200);
check('T04 response keys exactly', array_keys($r['data']), ['user_id', 'administrative_status', 'changed_at', 'event_id']);
check('T04 response values', [$r['data']['user_id'], $r['data']['administrative_status']], [$A, 'suspended']);
check('T04 no-store', $r['headers']->get('Cache-Control'), 'no-store, private');
check('T04 reason never in response', str_contains($r['raw'], $REASON), false);
$sA = state($A);
check('T04 durable state', [$sA['status'], $sA['version'], $sA['suspended_by'], $sA['suspended_at'] !== null], ['suspended', 2, 1, true]);
check('T04 PATs revoked, database sessions purged', [$sA['pats'], $sA['sessions']], [0, 0]);
be03Console();
$audit = DB::table('admin_audit_events')->where('event_id', $r['data']['event_id'])->first();
check('T04 audit row', [$audit->action, $audit->outcome, (int) $audit->admin_id, (int) $audit->target_user_id, $audit->from_status, $audit->to_status, (int) $audit->auth_version_before, (int) $audit->auth_version_after, $audit->reason],
    ['client.suspend', 'succeeded', 1, $A, 'active', 'suspended', 1, 2, $REASON]);
$outbox = DB::table('notification_outbox')->where('correlation_id', $r['data']['event_id'])->first();
check('T04 outbox intention', [$outbox->type, (int) $outbox->recipient_user_id, $outbox->status, (int) $outbox->attempts, json_decode($outbox->payload, true)], ['client.account_suspended', $A, 'pending', 0, ['template' => 'account_suspended']]);
check('T04 outbox carries no reason nor email', str_contains(json_encode($outbox), $REASON) || str_contains(json_encode($outbox), 'client.a@'), false);
check('T04 finances preserved', financeFingerprint(), $fingerprint);

// ================================================== T06 / T07 — refus client
$r = be03Client('GET', '/api/user', $sessFile);
check('T06 existing file session refused', [$r['status'], $r['data']['code'] ?? null], [403, 'CLIENT_SUSPENDED']);
check('T06 existing database session refused', in_array(be03Client('GET', '/api/user', $sessDb, [], 'database')['status'], [401, 403], true), true);
check('T06 file session closed after refusal', be03Client('POST', '/api/expenses', $sessFile, ['description' => 'x', 'amount' => 10, 'category' => 'Transport', 'date' => now()->toDateString()])['status'], 401);
check('T07 revoked PAT refused', be03Bearer($patA, 'GET', '/api/expenses')['status'], 401);
check('T07 PAT write refused', be03Bearer($patA, 'POST', '/api/expenses', ['description' => 'x', 'amount' => 10, 'category' => 'Transport', 'date' => now()->toDateString()])['status'], 401);
check('T06/T07 no financial write', financeFingerprint(), $fingerprint);

// ================================================= T05 — redondance (409)
check('T05 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
$r = be03Req('admin', 'POST', "/api/admin/users/$A/suspend", $adm, body('client.a@example.test'));
check('T05 re-suspension ⇒ 409 ALREADY_SUSPENDED', [$r['status'], $r['data']['code'] ?? null], [409, 'ALREADY_SUSPENDED']);
$sA = state($A);
check('T05 no business effect', [$sA['version'], $sA['audit_ok'], $sA['outbox']], [2, 1, 1]);
check('T05 refusal traced without reason', [$sA['audit_refused'], DB::table('admin_audit_events')->where('outcome', 'refused_redundant')->value('reason')], [1, null]);
$r = be03Req('admin', 'POST', "/api/admin/users/$B/reactivate", $adm, body('client.b@example.test'));
check('T05 reactivating an active account ⇒ 409 ALREADY_ACTIVE', [$r['status'], $r['data']['code'] ?? null], [409, 'ALREADY_ACTIVE']);
check('T05 active account untouched', [state($B)['version'], state($B)['pats']], [1, 1]);

// ======================================================= T09 / A1 — login, reset
$wrong = [];
$wr = be03ClientLogin($wrong, 'client.b@example.test', 'file', 'not-the-password');
$sus = [];
$sr = be03ClientLogin($sus, 'client.a@example.test');
check('A1 suspended login ⇒ generic 401', [$sr['status'], $sr['data']], [$wr['status'], $wr['data']]);
check('A1 no session after refused login', be03Client('GET', '/api/user', $sus)['status'], 401);
$anonJar = [];
$fr = be03Req('client', 'POST', '/api/forgot-password', $anonJar, ['email' => 'client.a@example.test']);
$fu = be03Req('client', 'POST', '/api/forgot-password', $anonJar, ['email' => 'unknown@example.test']);
check('T09 forgot-password neutral', [$fr['status'], $fr['data']], [$fu['status'], $fu['data']]);
be03Console();
check('T09 no reset token issued for suspended', DB::table('password_reset_tokens')->where('email', 'client.a@example.test')->count(), 0);
$token = Password::broker()->createToken(App\Models\User::find($A));
$hashBefore = DB::table('users')->where('id', $A)->value('password');
$rr = be03Req('client', 'POST', '/api/reset-password', $anonJar, ['token' => $token, 'email' => 'client.a@example.test', 'password' => 'new-fictional-pass-77', 'password_confirmation' => 'new-fictional-pass-77']);
$ri = be03Req('client', 'POST', '/api/reset-password', $anonJar, ['token' => 'bogus', 'email' => 'client.a@example.test', 'password' => 'new-fictional-pass-77', 'password_confirmation' => 'new-fictional-pass-77']);
check('T09 valid token on suspended ⇒ same as invalid link', [$rr['status'], $rr['data']], [$ri['status'], $ri['data']]);
be03Console();
check('T09 password unchanged', DB::table('users')->where('id', $A)->value('password'), $hashBefore);
check('T09 no access after reset attempt', be03ClientLogin($sus, 'client.a@example.test', 'file', 'new-fictional-pass-77')['status'], 401);

// ============================================= T08 — réactivation sans résurrection
check('T08 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
$r = be03Req('admin', 'POST', "/api/admin/users/$A/reactivate", $adm, body('client.a@example.test'));
check('T08 reactivation ⇒ 200 active', [$r['status'], $r['data']['administrative_status'] ?? null], [200, 'active']);
$sA = state($A);
check('T08 version 3, suspension metadata cleared', [$sA['status'], $sA['version'], $sA['suspended_at'], $sA['suspended_by']], ['active', 3, null, null]);
check('T08 old file session still invalid', be03Client('GET', '/api/user', $sessFile)['status'], 401);
check('T08 old database session still invalid', be03Client('GET', '/api/user', $sessDb, [], 'database')['status'], 401);
check('T08 old PAT still invalid', be03Bearer($patA, 'GET', '/api/user')['status'], 401);
$fresh = [];
check('T08 fresh login works', be03ClientLogin($fresh, 'client.a@example.test')['status'], 200);
check('T08 fresh session works', be03Client('GET', '/api/user', $fresh)['status'], 200);
check('T08 reactivation outbox', DB::table('notification_outbox')->where('recipient_user_id', $A)->orderBy('id')->pluck('type')->all(), ['client.account_suspended', 'client.account_reactivated']);

// ================================================ T16 / S17 — BE-06 + séparation
$r = be03Req('admin', 'GET', "/api/admin/users/$A", $adm);
check('BE-06 detail now carries BE-03 fields', [$r['status'], $r['data']['data']['administrative_status'] ?? null, $r['data']['data']['auth_version'] ?? null, array_key_exists('suspended_at', $r['data']['data'] ?? [])], [200, 'active', 3, true]);
$r2 = be03Req('admin', 'GET', '/api/admin/users?per_page=50', $adm);
check('BE-06 list carries BE-03 fields', collect($r2['data']['data'] ?? [])->every(fn ($u) => isset($u['administrative_status'], $u['auth_version'])), true);
check('F-02 list rows never carry suspended_at (detail only)', collect($r2['data']['data'] ?? [])->contains(fn ($u) => array_key_exists('suspended_at', $u)), false);
foreach (['detail' => $r, 'list' => $r2] as $label => $resp) {
    $raw = strtolower($resp['raw']);
    check("S17/T14 $label: no finance, reason or suspended_by", str_contains($raw, 'amount') || str_contains($raw, 'expense') || str_contains($raw, 'budget') || str_contains($raw, strtolower($REASON)) || str_contains($raw, 'suspended_by'), false);
}
// T14 : admin id 1 = client id 1
check('T14 admin and client share id 1', [$A, 1], [1, 1]);
$cross = $adm;
check('T14 admin cookie cannot read client finances', be03Client('GET', '/api/expenses', $cross)['status'], 401);
$cJar = $fresh;
check('T14 client session cannot reach admin mutation', in_array(be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $cJar, body('client.b@example.test'))['status'], [401, 419], true), true);
check('T14 B untouched', state($B)['status'], 'active');

// ======================================== T12 — échec audit / outbox ⇒ rollback
foreach (['admin_audit_events', 'notification_outbox'] as $table) {
    check("T12 reauthenticate ($table)", be03Reauth($adm, $secret)['status'], 200);
    be03Console();
    DB::statement("RENAME TABLE $table TO {$table}_off");
    $r = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, body('client.b@example.test'));
    be03Console();
    DB::statement("RENAME TABLE {$table}_off TO $table");
    check("T12 $table failure ⇒ 500 TRANSITION_FAILED", [$r['status'], $r['data']['code'] ?? null], [500, 'TRANSITION_FAILED']);
    check("T12 $table failure ⇒ no SQL leaked", str_contains(strtolower($r['raw']), 'sql') || str_contains($r['raw'], $table), false);
    $sB = state($B);
    check("T12 $table failure ⇒ full rollback", [$sB['status'], $sB['version'], $sB['pats'], $sB['audit_ok'], $sB['outbox']], ['active', 1, 1, 0, 0]);
}
check('T12 B PAT still works', be03Bearer($patB, 'GET', '/api/expenses')['status'], 200);

// ============================== T10 — concurrence avec écritures financières
$expense = ['description' => 'Écriture concurrente fictive', 'amount' => 777, 'category' => 'Loisirs', 'date' => now()->toDateString()];
$sessB = [];
check('T10 B login', be03ClientLogin($sessB, 'client.b@example.test')['status'], 200);
check('T10 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
be03Console();
$before = DB::table('expenses')->where('user_id', $B)->count();
[$w, $s] = holdRace('write-wins', ['kind' => 'client', 'method' => 'POST', 'uri' => '/api/expenses', 'jar' => $sessB, 'body' => $expense, 'hold' => ['sql' => 'insert into `expenses`', 'seconds' => HOLD]],
    fn () => be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, body('client.b@example.test')));
check('T10a in-flight write admitted first commits', $w['status'], 201);
check('T10a suspension waited for the write lock', $s['elapsed'] >= HOLD * 0.75, true);
check('T10a suspension then succeeds', $s['status'], 200);
// F-14 : ordre réel (horloge microtime de la même machine) : la suspension a
// démarré pendant que l'écriture tenait le verrou, et l'écriture a répondu
// (donc commité) AVANT que la suspension ne réponde.
check('T10a suspension started while the write held the lock', $s['start'] < $w['end'], true);
check('T10a write committed before the suspension completed', $w['end'] < $s['end'], true);
be03Console();
check('T10a exactly one new expense', DB::table('expenses')->where('user_id', $B)->count(), $before + 1);
check('T10a next write refused', in_array(be03Client('POST', '/api/expenses', $sessB, $expense)['status'], [401, 403], true), true);
check('T10a no further expense', DB::table('expenses')->where('user_id', $B)->count(), $before + 1);

// T10b : la suspension tient le verrou ; l'écriture arrive pendant ce temps.
check('T10b reauthenticate', be03Reauth($adm, $secret)['status'], 200);
check('T10b reactivate B', be03Req('admin', 'POST', "/api/admin/users/$B/reactivate", $adm, body('client.b@example.test'))['status'], 200);
check('T10b B login', be03ClientLogin($sessB, 'client.b@example.test')['status'], 200);
check('T10b reauthenticate', be03Reauth($adm, $secret)['status'], 200);
be03Console();
$before = DB::table('expenses')->where('user_id', $B)->count();
[$s, $w] = holdRace('suspension-wins', ['kind' => 'admin', 'method' => 'POST', 'uri' => "/api/admin/users/$B/suspend", 'jar' => $adm, 'body' => body('client.b@example.test'), 'hold' => ['sql' => 'insert into `notification_outbox`', 'seconds' => HOLD]],
    fn () => be03Client('POST', '/api/expenses', $sessB, $expense));
check('T10b suspension holding the lock succeeds', $s['status'], 200);
check('T10b concurrent write waited for the commit', $w['elapsed'] >= HOLD * 0.75, true);
check('T10b concurrent write refused after commit', [$w['status'], $w['data']['code'] ?? null], [403, 'CLIENT_SUSPENDED']);
be03Console();
check('T10b no expense written', DB::table('expenses')->where('user_id', $B)->count(), $before);

// T10c : login pendant une suspension en cours de validation.
check('T10c reauthenticate', be03Reauth($adm, $secret)['status'], 200);
check('T10c reactivate B', be03Req('admin', 'POST', "/api/admin/users/$B/reactivate", $adm, body('client.b@example.test'))['status'], 200);
check('T10c reauthenticate', be03Reauth($adm, $secret)['status'], 200);
$late = [];
[$s, $l] = holdRace('login-race', ['kind' => 'admin', 'method' => 'POST', 'uri' => "/api/admin/users/$B/suspend", 'jar' => $adm, 'body' => body('client.b@example.test'), 'hold' => ['sql' => 'insert into `notification_outbox`', 'seconds' => HOLD]],
    function () use (&$late) {
        $late = [];
        be03Req('client', 'GET', '/sanctum/csrf-cookie', $late);

        return be03Req('client', 'POST', '/api/login', $late, ['email' => 'client.b@example.test', 'password' => BE03_CLIENT_PASSWORD]);
    });
check('T10c suspension succeeds', $s['status'], 200);
check('T10c racing login waited and got the generic 401', [$l['status'], $l['elapsed'] >= HOLD * 0.75], [401, true]);
check('T10c racing login left no usable session', be03Client('GET', '/api/user', $late)['status'], 401);

// T10d : même course, sessions client « database ». La purge transactionnelle
// (DELETE … WHERE user_id) pose des verrous de plage sur l'index sessions.user_id :
// la création de session concurrente peut attendre le commit (latence bornée par la
// transition). Seul le résultat est affirmé ici, pas la chronologie.
check('T10d reauthenticate', be03Reauth($adm, $secret)['status'], 200);
check('T10d reactivate B', be03Req('admin', 'POST', "/api/admin/users/$B/reactivate", $adm, body('client.b@example.test'))['status'], 200);
check('T10d reauthenticate', be03Reauth($adm, $secret)['status'], 200);
$lateDb = [];
[$s, $l] = holdRace('login-race-db', ['kind' => 'admin', 'method' => 'POST', 'uri' => "/api/admin/users/$B/suspend", 'jar' => $adm, 'body' => body('client.b@example.test'), 'hold' => ['sql' => 'insert into `notification_outbox`', 'seconds' => HOLD]],
    function () use (&$lateDb) {
        $lateDb = [];
        be03Req('client', 'GET', '/sanctum/csrf-cookie', $lateDb, [], [], ['SESSION_DRIVER' => 'database']);

        return be03Req('client', 'POST', '/api/login', $lateDb, ['email' => 'client.b@example.test', 'password' => BE03_CLIENT_PASSWORD], [], ['SESSION_DRIVER' => 'database']);
    });
check('T10d suspension succeeds', $s['status'], 200);
check('T10d racing database-session login refused with the generic 401', $l['status'], 401);
check('T10d no usable database session', be03Client('GET', '/api/user', $lateDb, [], 'database')['status'], 401);

// ======================================== T11 — transitions simultanées
$adm2 = [];
$sec = $secret;
be03AdminSession($adm2, $sec);
// Deux sessions admin (même singleton) avec preuves récentes simultanées.
check('T11 reauthenticate second admin session', be03Reauth($adm2, $secret)['status'], 200);
check('T11 reauthenticate', be03Reauth($adm, $secret, false)['status'], 200);
[$ra, $rb] = barrierRace('double-suspend', ['kind' => 'admin', 'method' => 'POST', 'uri' => "/api/admin/users/$C/suspend", 'jar' => $adm, 'body' => body('client.c@example.test')],
    ['kind' => 'admin', 'method' => 'POST', 'uri' => "/api/admin/users/$C/suspend", 'jar' => $adm2, 'body' => body('client.c@example.test')]);
$statuses = [$ra['status'].':'.($ra['data']['code'] ?? $ra['data']['message'] ?? '-'), $rb['status'].':'.($rb['data']['code'] ?? $rb['data']['message'] ?? '-')];
sort($statuses);
check('T11 simultaneous suspensions ⇒ one 200, one 409', $statuses, ['200:-', '409:ALREADY_SUSPENDED']);
$sC = state($C);
check('T11 one transition, one event', [$sC['status'], $sC['version'], $sC['audit_ok'], $sC['audit_refused'], $sC['outbox']], ['suspended', 2, 1, 1, 1]);
check('T11 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
check('T11 reauthenticate second', be03Reauth($adm2, $secret, false)['status'], 200);
[$ra, $rb] = barrierRace('reactivate-vs-suspend', ['kind' => 'admin', 'method' => 'POST', 'uri' => "/api/admin/users/$C/reactivate", 'jar' => $adm, 'body' => body('client.c@example.test')],
    ['kind' => 'admin', 'method' => 'POST', 'uri' => "/api/admin/users/$C/suspend", 'jar' => $adm2, 'body' => body('client.c@example.test')]);
$sC = state($C);
$ok = (int) ($ra['status'] === 200) + (int) ($rb['status'] === 200);
check('T11 reactivate vs suspend serialize coherently', ($ok === 2 && $sC['version'] === 4) || ($ok === 1 && $sC['version'] === 3 && $sC['status'] === 'active'), true);
check('T11 outbox matches successful transitions', $sC['outbox'], 1 + $ok);
check('T11 audit matches successful transitions', $sC['audit_ok'], 1 + $ok);

// ============================ T13 — outbox durable (volet BE-03 ; envoi = BE-05)
be03Console();
check('T13 every intention durable and pending', DB::table('notification_outbox')->where('status', '<>', 'pending')->count(), 0);
check('T13 one intention per successful transition', DB::table('notification_outbox')->count(), DB::table('admin_audit_events')->where('outcome', 'succeeded')->count());
check('T13 intentions unique (dedup key)', DB::table('notification_outbox')->distinct()->count('event_id'), DB::table('notification_outbox')->count());

// ====================================== T15 — routes en cache
be03Console();
Artisan::call('route:cache');
$sus = [];
check('T15 cached routes: suspended login still generic 401', be03ClientLogin($sus, 'client.a@example.test', 'file', 'not-the-password')['status'], 401);
check('T15 reauthenticate (cached routes)', be03Reauth($adm, $secret)['status'], 200);
check('T15 cached routes: suspend works', be03Req('admin', 'POST', "/api/admin/users/$D/suspend", $adm, body('client.d@example.test'))['status'], 200);
$dJar = [];
check('T15 cached routes: suspended D refused at login', be03ClientLogin($dJar, 'client.d@example.test')['status'], 401);
be03Console();
Artisan::call('route:clear');

// ======================== F-04 / F-03 — no-store sur toutes les erreurs admin, 429
$nostore = fn (array $r) => $r['headers']->get('Cache-Control') === 'no-store, private';
$errs = [];
$anon = [];
be03Req('admin', 'GET', '/api/admin/csrf', $anon);
$errs['401 anonymous'] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $anon, body('client.b@example.test'));
$errs['401 bearer'] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, body('client.b@example.test'), ['HTTP_AUTHORIZATION' => 'Bearer x']);
$errs['403 origin'] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, body('client.b@example.test'), ['HTTP_ORIGIN' => BE03_CLIENT]);
$noCsrf = $adm;
unset($noCsrf['_csrf']);
$errs['419 csrf'] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $noCsrf, body('client.b@example.test'));
Carbon::setTestNow(now()->addSeconds(301));
$errs['403 recent auth'] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, body('client.b@example.test'));
check('F-04 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
$errs['422 validation'] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, ['reason' => '']);
$errs['404 unknown user'] = be03Req('admin', 'POST', '/api/admin/users/999999/suspend', $adm, body('x@example.test'));
$errs['404 no route'] = be03Req('admin', 'POST', '/api/admin/users/abc/suspend', $adm, body('x@example.test'));
$errs['405 wrong verb'] = be03Req('admin', 'GET', "/api/admin/users/$B/suspend", $adm);
$errs['409 redundant'] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, body('client.b@example.test')); // B suspendu depuis T10c
foreach ($errs as $label => $r) {
    check("F-04 $label carries no-store, private", [$r['status'], $nostore($r)], [(int) substr($label, 0, 3), true]);
}
// F-03 : 10 transitions / minute / IP après le grant MFA ; la 11e ⇒ 429, sans effet.
check('F-03 reauthenticate', be03Reauth($adm, $secret)['status'], 200);
$sB0 = state($B);
$codes = [];
for ($i = 0; $i < 11; $i++) {
    $codes[] = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, ['reason' => '', 'confirm_email' => 'client.b@example.test'])['status'];
}
$throttled = be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, body('client.b@example.test'));
check('F-03 ten requests admitted (422), then throttled', [array_count_values($codes)[422] ?? 0, $codes[10], $throttled['status']], [10, 429, 429]);
check('F-03 429 carries no-store, private', $nostore($throttled), true);
check('F-03 throttled requests had no effect', state($B), $sB0);
Carbon::setTestNow(now()->addSeconds(61));
check('F-03 limiter window resets', be03Req('admin', 'POST', "/api/admin/users/$B/suspend", $adm, ['reason' => ''])['status'], 422);

// ======================================== S16 / S20 — confidentialité, conservation
$log = @file_get_contents($runtime.'/logs/laravel.log') ?: '';
check('S16 reason never logged', str_contains($log, $REASON), false);
be03Console();
check('S20 no user deleted', DB::table('users')->count(), 4);
check('S20 finances: only the admitted concurrent write was added', DB::table('expenses')->count(), 4);
check('Q8 audit append-only: every row still present', DB::table('admin_audit_events')->count() >= 8, true);

$failed = count(array_filter($results, fn ($r) => ! $r['pass']));
// F-12 : une preuve PAR exécution (jamais écrasée), rattachée au code exact exécuté.
$git = fn (string $args) => trim((string) shell_exec('git -C '.escapeshellarg($root).' '.$args));
$evidence = [
    'engine' => $iso, 'image_digest' => getenv('BE03_DB_IMAGE_DIGEST') ?: null,
    'git_head' => $git('rev-parse HEAD'), 'git_dirty' => $git('status --porcelain') !== '',
    'php' => PHP_VERSION, 'started_at' => $startedAt, 'finished_at' => date(DATE_ATOM),
    'checks' => count($results), 'failed' => $failed, 'results' => $results,
];
$name = $runtime.'/evidence-'.preg_replace('/[^a-z0-9.]+/i', '-', $iso['version']).'-'.date('Ymd\THis').'-'.bin2hex(random_bytes(3)).'.json';
file_put_contents($name, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n".(count($results) - $failed).'/'.count($results)." checks passed — evidence: $name\n";
$GLOBALS['be03Completed'] = true;
exit($failed ? 1 : 0);
