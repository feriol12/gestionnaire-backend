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
| /home/htdocs/api.fluxmoneva.com --dirname(…, 2)--> /home
| /home + /fluxmoneva-api          --------------> racine Laravel privée
|
| Le chemin dépend uniquement de l'emplacement de ce fichier sur le disque,
| jamais de la requête HTTP ni d'une variable d'environnement.
|
*/

define('LARAVEL_START', microtime(true));

$laravelRoot = dirname(__DIR__, 2).'/fluxmoneva-api';

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
