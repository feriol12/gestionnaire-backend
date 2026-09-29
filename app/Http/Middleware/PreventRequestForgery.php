<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

class PreventRequestForgery extends Middleware
{
    /**
     * The URIs that should be excluded from request forgery verification.
     *
     * The API authenticates with Sanctum Bearer tokens (no session cookie),
     * so CSRF/origin verification does not apply to it.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/*',
    ];
}
