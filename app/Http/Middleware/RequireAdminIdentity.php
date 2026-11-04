<?php

namespace App\Http\Middleware;

use App\Models\AdminIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->user() instanceof AdminIdentity) {
            return response()->json(['code' => 'ADMIN_AUTH_REQUIRED'], 401);
        }

        return $next($request);
    }
}
