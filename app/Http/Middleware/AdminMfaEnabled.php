<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class AdminMfaEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (config('admin.mfa_enabled') !== true
            || !in_array(config('admin.session.connection'), [null, config('database.default')], true)) {
            return response()->json(['code' => 'ADMIN_UNAVAILABLE'], 503, ['Cache-Control' => 'no-store, private']);
        }
        return $next($request);
    }
}
