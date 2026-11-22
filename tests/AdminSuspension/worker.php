<?php

/*
 * BE-03 — processus PHP indépendant pour les tests de concurrence.
 * Usage interne : php worker.php <dossier-course> <slot>
 *
 * input-<slot>.json : now, kind, method, uri, jar, body, env, wait_go,
 * hold = {sql: préfixe SQL, seconds} — après l'exécution de cette requête
 * SQL (donc verrous tenus, transaction ouverte), le worker crée
 * locked-<slot> puis dort : la course est déterministe.
 */

require __DIR__.'/lib.php';

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;

[$dir, $slot] = [$argv[1], $argv[2]];
$input = json_decode(file_get_contents($dir.'/input-'.$slot.'.json'), true);
Carbon::setTestNow(Carbon::parse($input['now']));

$hold = $input['hold'] ?? null;
$jar = $input['jar'];

touch($dir.'/ready-'.$slot);
if (! empty($input['wait_go'])) {
    $until = microtime(true) + 30;
    while (! is_file($dir.'/go')) {
        if (microtime(true) > $until) {
            exit(3);
        }
        usleep(5000);
    }
}

$result = (function () use ($input, &$jar, $hold, $dir, $slot) {
    $kind = $input['kind'];
    $base = $kind === 'admin' ? BE03_ADMIN : BE03_API;
    $env = $input['env'] ?? [];
    if ($kind === 'admin' && ! isset($env['SESSION_DRIVER'])) {
        $env['SESSION_DRIVER'] = 'database'; // identique à be03Req()
    }
    $app = be03App($env);
    // Écouteur posé avant le traitement : il s'exécute dans la transaction ouverte.
    if ($hold !== null) {
        $app['events']->listen(QueryExecuted::class, function (QueryExecuted $q) use ($hold, $dir, $slot) {
            if (str_starts_with(strtolower($q->sql), strtolower($hold['sql'])) && ! is_file($dir.'/locked-'.$slot)) {
                touch($dir.'/locked-'.$slot);
                usleep((int) ($hold['seconds'] * 1000000));
            }
        });
    }
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $cookies = $jar;
    unset($cookies['_csrf'], $cookies['_bearer']);
    $headers = ['HTTPS' => 'on', 'SERVER_PORT' => 443, 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json'];
    if ($kind === 'admin') {
        $headers += ['HTTP_ORIGIN' => BE03_ADMIN, 'HTTP_REFERER' => BE03_ADMIN.'/', 'HTTP_X_CSRF_TOKEN' => $jar['_csrf'] ?? ''];
    } elseif ($kind === 'client') {
        $headers += ['HTTP_ORIGIN' => BE03_CLIENT, 'HTTP_REFERER' => BE03_CLIENT.'/', 'HTTP_X_XSRF_TOKEN' => $jar['XSRF-TOKEN'] ?? ''];
    } else {
        $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$jar['_bearer'];
        $cookies = [];
    }
    $body = $input['body'] ?? [];
    $request = Illuminate\Http\Request::create($base.$input['uri'], $input['method'], [], $cookies, [], $headers, $body === [] ? null : json_encode($body));
    $start = microtime(true);
    $response = $kernel->handle($request);
    $end = microtime(true);
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'data' => json_decode((string) $response->getContent(), true), 'start' => $start, 'end' => $end];
})();

file_put_contents($dir.'/result-'.$slot.'.json', json_encode($result));
exit(0);
