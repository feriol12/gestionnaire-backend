<?php

/*
 * BE-06 — admin users HTTP/MFA integration harness bootstrap.
 *
 * Conventions copied from the existing standalone tests/AdminMfa harness:
 * a fresh application per request, isolated runtime storage, explicit
 * environment values, no dependence on the developer workstation state.
 * This file creates nothing outside its own runtime (storage/app/
 * admin-be06-users) and does not modify any production or foundational file.
 *
 * OPS-02 gate: run.php refuses to execute without the explicit 'run'
 * argument. A database-backed execution stays reserved to a qualified
 * and owner-validated isolation profile.
 */

// Accept both layouts: a classic repository has .git/HEAD directly, while
// a Git worktree has .git as a FILE containing "gitdir: <path>" (absolute,
// or relative to the checkout root). Resolve the pointer and require the
// real Git directory to contain HEAD.
$root = dirname(__DIR__, 2);
$gitPath = $root.'/.git';
if (is_file($gitPath.'/HEAD')) {
    // Classic checkout: .git is the Git directory itself.
    $gitDir = $gitPath;
} else {
    $pointer = is_file($gitPath) ? trim((string) file_get_contents($gitPath)) : '';
    if (!str_starts_with($pointer, 'gitdir:')) {
        throw new RuntimeException('Independent BE-06 checkout required');
    }
    $target = trim(substr($pointer, strlen('gitdir:')));
    if ($target === '') {
        throw new RuntimeException('Independent BE-06 checkout required');
    }
    // Absolute pointer (drive letter, UNC share or POSIX root) is used as
    // is; anything else is resolved relative to the checkout root. realpath()
    // canonicalizes (../ segments, separators) and fails on a dead pointer.
    $absolute = preg_match('#^(?:[A-Za-z]:[/\\\\]|\\\\\\\\|/)#', $target) === 1;
    $gitDir = str_replace('\\', '/', rtrim((string) realpath($absolute ? $target : $root.'/'.$target), '/'));
    if ($gitDir === '' || !is_file($gitDir.'/HEAD')) {
        throw new RuntimeException('Independent BE-06 checkout required');
    }
}

$runtime = $root.'/storage/app/admin-be06-users/runtime';
foreach (['config', 'cache', 'tmp', 'sessions', 'views', 'evidence'] as $dir) {
    if (!is_dir($runtime.'/'.$dir)) mkdir($runtime.'/'.$dir, 0700, true);
}

// Harness dirs must ALSO reproduce Laravel's conventional storage layout for
// the default file drivers: file sessions (config/session.php defaults to
// 'file'), file cache (CACHE_STORE=file resolves to
// storage_path('framework/cache/data')) and compiled views. Without
// framework/sessions the first stateful client request fails while writing
// its session file and returns 500 instead of the expected status.
foreach (['framework/sessions', 'framework/cache/data', 'framework/views'] as $dir) {
    if (!is_dir($runtime.'/'.$dir)) mkdir($runtime.'/'.$dir, 0700, true);
}

// Dedicated disposable SQLite file: created EMPTY here if and only if it is
// absent, so Laravel's first PRAGMA (busy_timeout) never hits a missing
// database file. An existing file is NEVER overwritten, truncated or
// recreated (fopen 'x' fails if the file already exists); the schema itself
// stays the responsibility of run.php's initBe06Schema() behind the OPS-02
// gate. The path stays inside this isolated runtime directory.
$database = $runtime.'/db.sqlite';
if (!is_file($database)) {
    if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) {
        throw new RuntimeException('Unable to create the BE-06 runtime directory');
    }
    $handle = @fopen($database, 'x');
    if ($handle === false) {
        // Concurrent creation is tolerable (the file must never be
        // overwritten); only a genuinely absent file is a hard failure.
        if (!is_file($database)) {
            throw new RuntimeException('Unable to create the dedicated BE-06 SQLite file');
        }
    } else {
        fclose($handle);
    }
}

foreach ([
    'APP_ENV' => 'admin-be06-test',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('B', 32)),
    'APP_URL' => 'https://client.foundation.test',
    'FRONTEND_URL' => 'https://client.foundation.test',
    // Dedicated disposable SQLite file: the harness stays self-contained.
    // MariaDB/production-like runs remain reserved to the OPS-02-qualified
    // environment and are explicitly NOT enabled here by default.
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'CACHE_STORE' => 'file',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    // Production admin switches mirrored from the MFA lot: routes are only
    // registered because mfa_enabled is true in THIS isolated runtime.
    'ADMIN_MFA_ENABLED' => 'true',
    'ADMIN_ORIGIN' => 'https://client.foundation.test',
    'APP_CONFIG_CACHE' => 'storage/app/admin-be06-users/runtime/cache/config.php',
    'APP_ROUTES_CACHE' => 'storage/app/admin-be06-users/runtime/cache/routes.php',
    'APP_SERVICES_CACHE' => 'storage/app/admin-be06-users/runtime/cache/services.php',
    'APP_PACKAGES_CACHE' => 'storage/app/admin-be06-users/runtime/cache/packages.php',
    'APP_EVENTS_CACHE' => 'storage/app/admin-be06-users/runtime/cache/events.php',
] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}

require_once $root.'/vendor/autoload.php';

/** Mirrors mfaApp(): a brand-new application for every request. */
function be06App() {
    $root = dirname(__DIR__, 2);
    $runtime = $root.'/storage/app/admin-be06-users/runtime';
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('NO-ENV-BE06-TEST');
    $app->useStoragePath($runtime);
    return $app;
}
