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

        // AUTH3-03 : limiteurs dédiés à la récupération de mot de passe. Même clé
        // IP + email haché : identique que le compte existe ou non (pas
        // d'énumération par le limiteur), avec un plafond par IP en plus pour
        // limiter le balayage de nombreuses adresses depuis une même machine.
        RateLimiter::for('forgot-password', function (Request $request) {
            return [
                Limit::perMinute(3)->by(self::authThrottleKey('forgot-password', $request)),
                Limit::perMinute(10)->by('forgot-password-ip|'.hash('sha256', (string) $request->ip())),
            ];
        });

        RateLimiter::for('reset-password', function (Request $request) {
            return [
                Limit::perMinute(5)->by(self::authThrottleKey('reset-password', $request)),
                Limit::perMinute(20)->by('reset-password-ip|'.hash('sha256', (string) $request->ip())),
            ];
        });

        // AUTH3-04 : renvoi de l'email de vérification, mêmes conventions que la
        // récupération de mot de passe (clé indépendante de l'existence du compte).
        RateLimiter::for('verification-resend', function (Request $request) {
            return [
                Limit::perMinute(3)->by(self::authThrottleKey('verification-resend', $request)),
                Limit::perMinute(10)->by('verification-resend-ip|'.hash('sha256', (string) $request->ip())),
            ];
        });

        // AUTH3-04 : lien de vérification (GET ouvert depuis l'email), plafond
        // modeste par IP. Ouvert dans le navigateur : au-delà, retour vers la page
        // du SPA plutôt qu'une page d'erreur HTML.
        RateLimiter::for('verification-link', function (Request $request) {
            return Limit::perMinute(20)
                ->by('verification-link-ip|'.hash('sha256', (string) $request->ip()))
                ->response(fn () => redirect()->away(config('app.frontend_url').'/verify-email?verification=rate_limited'));
        });

        // AUTH3-05 : changement de mot de passe (route authentifiée) : clé =
        // identifiant du compte + IP, hachée ; borne les essais sur le mot de
        // passe actuel depuis une session détournée.
        RateLimiter::for('password-change', function (Request $request) {
            return Limit::perMinute(5)->by('password-change|'.hash('sha256', $request->user()?->getAuthIdentifier().'|'.$request->ip()));
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
