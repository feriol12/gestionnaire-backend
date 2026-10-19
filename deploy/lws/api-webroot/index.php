<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| LWS-BE-03 : point d'entrée public de l'API sur LWS
|--------------------------------------------------------------------------
|
| Copié tel quel vers /home/htdocs/api.fluxmoneva.com/index.php (racine web
| de l'API). L'application Laravel reste privée, hors de toute racine web,
| dans /home/fluxmoneva-api. LWS refuse les liens symboliques depuis la
| racine web (403) : ce fichier est donc une vraie copie de public/index.php
| dont seuls les chemins changent ; le cycle de la requête est identique.
|
| LWS-BE-08-F1 : la racine Laravel privée est un chemin absolu fixe, invariant
| de l'infrastructure LWS. Elle n'est plus déduite de __DIR__ : le PHP HTTP de
| LWS voit /home/htdocs sous son nom canonique /htdocs, si bien que
| dirname(__DIR__, 2) vaut « / » et non « /home » (racine « //fluxmoneva-api »
| inexistante, observée en répétition réelle).
|
| Le chemin ne dépend ni de la requête HTTP, ni d'une variable
| d'environnement, ni de DOCUMENT_ROOT, ni du répertoire courant.
|
*/

define('LARAVEL_START', microtime(true));

$laravelRoot = '/home/fluxmoneva-api';

// Mode maintenance (php artisan down) : avant les dépendances de démarrage.
if (file_exists($maintenance = $laravelRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Fichiers de démarrage absents : 500 sans corps délibéré.
// Les permissions, open_basedir et display_errors restent à valider sur LWS.
if (! is_file($laravelRoot.'/vendor/autoload.php') || ! is_file($laravelRoot.'/bootstrap/app.php')) {
    http_response_code(500);

    exit;
}

require $laravelRoot.'/vendor/autoload.php';

$app = require_once $laravelRoot.'/bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
