<?php

use App\Http\Controllers\Admin\AdminAuthController as Controller;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminUserStatusController;
use App\Http\Middleware;
use Illuminate\Support\Facades\Route;

// Never nest under the client api/Sanctum middleware group.
Route::prefix('api/admin')->middleware([
    Middleware\AdminMfaEnabled::class,
    Middleware\SelectAdminContext::class,
    Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    Middleware\AdminCsrf::class,
])->group(function () {
    Route::get('csrf', [Controller::class, 'csrf']);
    Route::post('login', [Controller::class, 'login']);
    Route::post('enrollment', [Controller::class, 'enrollment']);
    Route::post('mfa', [Controller::class, 'verify']);
    Route::post('recovery', [Controller::class, 'recover']);
    Route::post('logout', [Controller::class, 'logout']);
    Route::middleware(Middleware\RequireAdminMfa::class)->group(function () {
        Route::get('session', [Controller::class, 'session']);
        Route::post('reauthenticate', [Controller::class, 'reauthenticate']);
        Route::post('factor/change', [Controller::class, 'change']);
        // BE-06 — read-only user directory. No mutation, no finance data.
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{id}', [AdminUserController::class, 'show'])->whereNumber('id');
        // BE-03 — transitions administratives : MFA récente ≤300 s, motif et
        // courriel exact revérifiés dans la transaction (ClientAccountTransitions).
        Route::post('users/{id}/suspend', [AdminUserStatusController::class, 'suspend'])->whereNumber('id')
            ->middleware('throttle:admin-user-transition');
        Route::post('users/{id}/reactivate', [AdminUserStatusController::class, 'reactivate'])->whereNumber('id')
            ->middleware('throttle:admin-user-transition');
    });
});
