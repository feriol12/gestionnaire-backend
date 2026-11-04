<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminMfa;
use Closure;
use Illuminate\Http\Request;

final class RequireAdminMfa
{
    public function handle(Request $request, Closure $next)
    {
        app(AdminMfa::class)->requireGrant($request);
        return $next($request);
    }
}
