<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Must follow auth:sanctum and precede client activity/business middleware. */
final class RequireClientIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! Auth::guard('sanctum')->user() instanceof User
            || ! Auth::user() instanceof User || Auth::id() !== $user->getAuthIdentifier()) {
            return response()->json(['code' => 'CLIENT_IDENTITY_REQUIRED'], 403);
        }

        return $next($request);
    }
}
