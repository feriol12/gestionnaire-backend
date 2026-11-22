<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'otp',
        'recovery_code',
        // BE-03 (SEC-01 S16) : motif administratif et confirmation de cible.
        'reason',
        'confirm_email',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * BE-03 (OpenCode F-04, GOV-05 §1) : toute réponse d'erreur sous
     * /api/admin/* est non mise en cache, y compris celles produites hors de
     * la pile admin (aucune route correspondante, mauvais verbe, limiteur).
     * SelectAdminContext couvre déjà les erreurs levées dans la pile.
     */
    public function render($request, Throwable $e)
    {
        $response = parent::render($request, $e);
        if ($request->is('api/admin', 'api/admin/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
