<?php

namespace App\Http\Middleware;

/** Uses the admin session token and X-CSRF-TOKEN, never a shared XSRF cookie. */
final class AdminCsrf extends PreventRequestForgery
{
    protected $addHttpCookie = false;

    protected function hasValidOrigin($request)
    {
        return false; // A token is required even for same-origin requests.
    }

    protected function getTokenFromRequest($request)
    {
        return $request->header('X-CSRF-TOKEN');
    }
}
