<?php

/*
 * BE-03 — banc HTTP de bout en bout (suspension / réactivation).
 *
 * Mêmes conventions que tests/AdminMfa et tests/AdminUsersHttp : une
 * application NEUVE par requête (imposé par SelectAdminContext), cookies
 * gérés à la main, horloge Carbon figée partagée avec les workers.
 *
 * ISOLATION (OPS-02) — le banc refuse de démarrer sauf si :
 *   - BE03_DB_DATABASE se termine par « _test » ;
 *   - le serveur joint répond avec @@port = BE03_DB_PORT et
 *     @@datadir = BE03_DB_EXPECTED_DATADIR (instance jetable dédiée).
 * Il exécute ensuite migrate:fresh sur CETTE base uniquement.
 * Données exclusivement fictives (example.test).
 */

$root = dirname(__DIR__, 2);
$runtime = $root.'/storage/app/admin-be03/runtime';
// Le banc principal repart d'un runtime vierge : cache des limiteurs, sessions
// fichier, journaux et courses d'une exécution précédente (horloge figée
// différente) ne doivent pas fausser la suivante. Jamais dans un worker.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'run.php' && is_dir($runtime)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if (! str_starts_with($f->getFilename(), 'evidence-')) {
            $f->isDir() ? @rmdir($f->getPathname()) : unlink($f->getPathname());
        }
    }
}
foreach (['cache', 'framework/sessions', 'framework/cache/data', 'framework/views', 'logs', 'race'] as $dir) {
    if (! is_dir($runtime.'/'.$dir)) {
        mkdir($runtime.'/'.$dir, 0700, true);
    }
}

function be03Env(string $key): string
{
    $value = getenv($key);
    if (! is_string($value)) {
        throw new RuntimeException("Missing $key: refusing to guess an isolated database.");
    }

    return $value;
}

$database = be03Env('BE03_DB_DATABASE');
if (preg_match('/\A[a-z0-9_]+_test\z/', $database) !== 1) {
    throw new RuntimeException('BE03_DB_DATABASE must end with _test.');
}

foreach ([
    'APP_ENV' => 'admin-be03-test',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('C', 32)),
    'APP_URL' => 'https://api.foundation.test',
    'FRONTEND_URL' => 'https://client.foundation.test',
    'SANCTUM_STATEFUL_DOMAINS' => 'client.foundation.test',
    'SESSION_COOKIE' => 'fm_client_session',
    'SESSION_DRIVER' => 'file',
    'DB_CONNECTION' => getenv('BE03_DB_DRIVER') ?: 'mysql',
    'DB_HOST' => be03Env('BE03_DB_HOST'),
    'DB_PORT' => be03Env('BE03_DB_PORT'),
    'DB_DATABASE' => $database,
    'DB_USERNAME' => be03Env('BE03_DB_USERNAME'),
    'DB_PASSWORD' => getenv('BE03_DB_PASSWORD') ?: '',
    'DATABASE_URL' => '',
    // Cache fichier du runtime (config/cache.php lit CACHE_DRIVER) : les
    // limiteurs restent réalistes d'une requête (application neuve) à l'autre.
    'CACHE_DRIVER' => 'file',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'single',
    'ADMIN_MFA_ENABLED' => 'true',
    'ADMIN_ORIGIN' => 'https://admin.foundation.test',
    'APP_CONFIG_CACHE' => 'storage/app/admin-be03/runtime/cache/config.php',
    'APP_ROUTES_CACHE' => 'storage/app/admin-be03/runtime/cache/routes.php',
    'APP_SERVICES_CACHE' => 'storage/app/admin-be03/runtime/cache/services.php',
    'APP_PACKAGES_CACHE' => 'storage/app/admin-be03/runtime/cache/packages.php',
    'APP_EVENTS_CACHE' => 'storage/app/admin-be03/runtime/cache/events.php',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require_once $root.'/vendor/autoload.php';

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const BE03_API = 'https://api.foundation.test';
const BE03_CLIENT = 'https://client.foundation.test';
const BE03_ADMIN = 'https://admin.foundation.test';
const BE03_ADMIN_EMAIL = 'super.admin@example.test';
const BE03_ADMIN_PASSWORD = 'fictional-admin-password-0001';
const BE03_CLIENT_PASSWORD = 'fictional-client-password-01';

/** Application neuve ; $env : surcharges pour CETTE requête (ex. SESSION_DRIVER). */
function be03App(array $env = []): Illuminate\Foundation\Application
{
    global $root, $runtime;
    foreach ($env as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('NO-ENV-BE03-TEST');
    $app->useStoragePath($runtime);

    return $app;
}

/** Application amorcée pour les accès directs (assertions SQL, préparation). */
function be03Console(): Illuminate\Foundation\Application
{
    $app = be03App();
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    return $app;
}

/** Vérifie que la connexion vise l'instance jetable attendue, sinon arrêt. */
function be03AssertIsolated(): array
{
    $server = DB::selectOne('SELECT @@version AS version, @@port AS port, @@datadir AS datadir');
    $norm = fn (string $p) => strtolower(rtrim(str_replace('\\', '/', $p), '/'));
    $expected = $norm((string) realpath(be03Env('BE03_DB_EXPECTED_DATADIR')));
    if ($expected === '' || (int) $server->port !== (int) be03Env('BE03_DB_PORT') || $norm($server->datadir) !== $expected
        || DB::connection()->getDatabaseName() !== be03Env('BE03_DB_DATABASE')) {
        throw new RuntimeException('Wrong SQL instance: refusing to touch it.');
    }

    return ['version' => $server->version, 'port' => (int) $server->port, 'database' => DB::connection()->getDatabaseName()];
}

/**
 * Une requête HTTP complète dans une application neuve.
 * $jar : pot de cookies (+ _csrf admin) ; $kind : 'admin' | 'client' | 'bearer'.
 */
function be03Req(string $kind, string $method, string $uri, array &$jar, array $body = [], array $server = [], array $env = []): array
{
    $app = be03App($env);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $base = $kind === 'admin' ? BE03_ADMIN : BE03_API;
    $cookies = $jar;
    unset($cookies['_csrf'], $cookies['_bearer']);
    $headers = ['HTTPS' => 'on', 'SERVER_PORT' => 443, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json'];
    if ($kind === 'admin') {
        $headers += ['HTTP_ORIGIN' => BE03_ADMIN, 'HTTP_REFERER' => BE03_ADMIN.'/'];
        if (isset($jar['_csrf'])) {
            $headers['HTTP_X_CSRF_TOKEN'] = $jar['_csrf'];
        }
    } elseif ($kind === 'client') {
        $headers += ['HTTP_ORIGIN' => BE03_CLIENT, 'HTTP_REFERER' => BE03_CLIENT.'/'];
        if (isset($jar['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
        }
    } else {
        $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$jar['_bearer'];
        $cookies = [];
    }
    $request = Illuminate\Http\Request::create($base.$uri, $method, [], $cookies, [], array_replace($headers, $server), $body === [] ? null : json_encode($body));
    $started = microtime(true);
    $response = $kernel->handle($request);
    $elapsed = microtime(true) - $started;
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getValue() === null || $cookie->getValue() === '' || $cookie->isCleared()) {
            unset($jar[$cookie->getName()]);
        } else {
            $jar[$cookie->getName()] = $cookie->getValue();
        }
    }
    $raw = (string) $response->getContent();
    $data = json_decode($raw, true) ?? [];
    if ($kind === 'admin' && isset($data['csrf'])) {
        $jar['_csrf'] = $data['csrf'];
    }
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'data' => $data, 'raw' => $raw, 'headers' => $response->headers, 'elapsed' => $elapsed];
}

function be03Otp(string $secret, int $offset = 0): string
{
    return (new PragmaRX\Google2FA\Google2FA)->oathTotp($secret, intdiv(now()->timestamp, 30) + $offset);
}

/** Avance l'horloge partagée d'un pas TOTP complet (nouveau code acceptable). */
function be03Tick(int $seconds = 30): void
{
    Carbon::setTestNow(now()->addSeconds($seconds));
}

/** Session admin complète (login + TOTP) ; enrôle au premier appel. */
function be03AdminSession(array &$jar, ?string &$secret): void
{
    $jar = [];
    be03Req('admin', 'GET', '/api/admin/csrf', $jar);
    $r = be03Req('admin', 'POST', '/api/admin/login', $jar, ['email' => BE03_ADMIN_EMAIL, 'password' => BE03_ADMIN_PASSWORD]);
    if ($r['status'] !== 200) {
        throw new RuntimeException('admin login failed: '.$r['status']);
    }
    if ($secret === null) {
        $secret = be03Req('admin', 'POST', '/api/admin/enrollment', $jar)['data']['secret'] ?? null;
    }
    be03Tick();
    $r = be03Req('admin', 'POST', '/api/admin/mfa', $jar, ['otp' => be03Otp($secret)]);
    if ($r['status'] !== 200) {
        throw new RuntimeException('admin mfa failed: '.$r['status'].' '.$r['raw']);
    }
}

function be03Reauth(array &$jar, string $secret, bool $newWindow = true): array
{
    // Saut au début de la fenêtre suivante de 300 s : la limitation MFA
    // existante (5 essais / identité / fenêtre, BE-02) n'est pas contournée,
    // simplement pas épuisée par le banc ; le pas TOTP change aussi.
    // Sans nouvelle fenêtre : un pas TOTP (30 s) pour un code non rejoué.
    Carbon::setTestNow($newWindow ? Carbon::createFromTimestamp((intdiv(now()->timestamp, 300) + 1) * 300 + 1) : now()->addSeconds(30));

    return be03Req('admin', 'POST', '/api/admin/reauthenticate', $jar, ['password' => BE03_ADMIN_PASSWORD, 'otp' => be03Otp($secret)]);
}

function be03ClientLogin(array &$jar, string $email, string $driver = 'file', string $password = BE03_CLIENT_PASSWORD): array
{
    $jar = [];
    be03Req('client', 'GET', '/sanctum/csrf-cookie', $jar, [], [], ['SESSION_DRIVER' => $driver]);

    return be03Req('client', 'POST', '/api/login', $jar, ['email' => $email, 'password' => $password], [], ['SESSION_DRIVER' => $driver]);
}

function be03Client(string $method, string $uri, array &$jar, array $body = [], string $driver = 'file'): array
{
    return be03Req('client', $method, $uri, $jar, $body, [], ['SESSION_DRIVER' => $driver]);
}

function be03Bearer(string $token, string $method, string $uri, array $body = []): array
{
    $jar = ['_bearer' => $token];

    return be03Req('bearer', $method, $uri, $jar, $body);
}
