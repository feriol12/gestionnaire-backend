<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // AUTH3-03 : l'API n'a pas de route web « password.reset » ; le lien de
        // réinitialisation pointe vers la page du SPA.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return config('app.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        // AUTH3-04 : lien de vérification = URL signée temporaire native (id +
        // hash de l'email + expiration), signée en relatif (chemin + paramètres)
        // puis préfixée par APP_URL : la signature ne dépend pas de l'hôte ni du
        // schéma vus par l'application derrière un proxy TLS.
        VerifyEmail::createUrlUsing(function ($user) {
            return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ],
                false
            );
        });
    }
}
