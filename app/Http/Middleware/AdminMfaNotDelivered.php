<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fail closed until the real MFA lot replaces this gate after independent review. */
final class AdminMfaNotDelivered
{
    public function handle(Request $request, Closure $next): Response
    {
        // No environment flag or session claim can bypass this gate.
        return response()->json(['code' => 'ADMIN_MFA_NOT_DELIVERED'], 403);
    }
}
