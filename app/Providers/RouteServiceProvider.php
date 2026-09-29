<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // SEC-006 : limiteurs dédiés aux routes publiques d'authentification.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(self::authThrottleKey('login', $request));
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by(self::authThrottleKey('register', $request));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Clé "IP + email normalisé", hachée pour ne pas stocker l'email en clair
     * dans le cache. Le limiteur s'exécute AVANT la validation du contrôleur :
     * un email absent ou non textuel (tableau, nombre...) ne doit jamais
     * provoquer d'erreur ici, il retombe simplement sur une valeur vide.
     */
    private static function authThrottleKey(string $bucket, Request $request): string
    {
        $email = $request->input('email');
        $email = is_string($email) ? Str::lower(trim($email)) : '';

        return $bucket.'|'.hash('sha256', $request->ip().'|'.$email);
    }
}
