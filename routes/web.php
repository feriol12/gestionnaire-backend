<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// LWS-BE-04 : l'API n'expose aucune ressource à la racine (GET / → 404, sans
// session ni cookie). La sonde de disponibilité est /up, enregistrée dans le
// RouteServiceProvider hors des groupes « web » et « api ».
