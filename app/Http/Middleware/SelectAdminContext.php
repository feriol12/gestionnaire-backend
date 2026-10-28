<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/** Request-scoped selection. Unsupported on a reused application container. */
final class SelectAdminContext
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->app->resolved('session') || $this->app->resolved('session.store') || $this->app->resolved('auth')) {
            throw new LogicException('Administrative context must precede session/auth resolution.');
        }

        $origin = config('admin.origin');
        $parts = is_string($origin) ? parse_url($origin) : false;
        if (! $parts || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($parts['host'], '*')) {
            return response()->json(['code' => 'ADMIN_UNAVAILABLE'], 503, ['Cache-Control' => 'no-store, private']);
        }
        if ($request->getSchemeAndHttpHost() !== $origin
            || ($request->headers->has('Origin') && $request->header('Origin') !== $origin)) {
            return response()->json(['code' => 'ADMIN_ORIGIN_REFUSED'], 403, ['Cache-Control' => 'no-store, private']);
        }
        if ($request->bearerToken() !== null) {
            return response()->json(['code' => 'ADMIN_BEARER_REFUSED'], 401, ['Cache-Control' => 'no-store, private']);
        }

        $session = config('admin.session');
        if (!is_array($session) || !is_string($session['cookie'] ?? null) || $session['cookie'] === ''
            || $session['cookie'] === config('session.cookie')
            || ($session['table'] ?? null) === config('session.table')
            || ($session['path'] ?? null) !== '/api/admin'
            || ($session['table'] ?? null) !== 'admin_sessions') {
            throw new LogicException('Administrative session configuration is not isolated.');
        }

        config([
            'auth.defaults.guard' => 'admin',
            'session.driver' => 'database',
            'session.connection' => $session['connection'],
            'session.table' => $session['table'],
            'session.cookie' => $session['cookie'],
            'session.path' => $session['path'],
            'session.domain' => null,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'strict',
            'session.lifetime' => $session['lifetime'],
            'session.expire_on_close' => true,
        ]);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
