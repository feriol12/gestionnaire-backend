<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

class PreventRequestForgery extends Middleware
{
    /**
     * The URIs that should be excluded from request forgery verification.
     *
     * AUTH3-01 : aucune exclusion. Le navigateur s'authentifie par cookie de
     * session (Sanctum SPA) : Sanctum applique ce middleware à toute requête
     * API venant d'un domaine « stateful », qui doit donc porter X-XSRF-TOKEN.
     * Les clients non navigateur authentifiés par jeton Bearer ne sont pas
     * « stateful » : Sanctum ne leur applique pas ce middleware.
     *
     * @var array<int, string>
     */
    protected $except = [];
}
