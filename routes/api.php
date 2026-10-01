<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExpenseController;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BudgetExportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PasswordResetController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
// AUTH
// Limiteurs dédiés (SEC-006, voir RouteServiceProvider), en plus du limiteur générique "api"
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
// AUTH3-03 : récupération de mot de passe (publique, CSRF pour le SPA, limiteurs dédiés)
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:forgot-password');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:reset-password');
// AUTH3-04 : vérification d'email. Le lien signé fonctionne sans session (pas de
// connexion automatique) ; le renvoi est public (CSRF pour le SPA, limiteur dédié).
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:verification-link')
    ->name('verification.verify');
Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:verification-resend');
// Protégez la route logout avec Sanctum
// Volontairement SANS activity.check : un jeton inactif doit pouvoir être révoqué.
// AUTH3-01 (A3-F1) : et SANS le limiteur générique « api » (60/min) : un budget de
// requêtes épuisé ne doit jamais empêcher la fermeture de la session. Session,
// CSRF (Sanctum stateful) et auth:sanctum restent appliqués.
Route::middleware('auth:sanctum')
    ->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':api')
    ->post('/logout', [AuthController::class, 'logout']);
// Route::post('/logout', [AuthController::class, 'logout']);

    //routes expenses

// AUTH3-04 : toutes les ressources métier exigent une adresse email vérifiée
// (« verified » après activity.check). /user et logout restent accessibles à
// une session non vérifiée (antérieure à AUTH3-04) pour l'orienter / la fermer.
Route::middleware(['auth:sanctum', 'activity.check', 'verified'])->group(function () {
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses/{id}', [ExpenseController::class, 'show']);
    Route::put('/expenses/{id}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy']);
    Route::get('/export/pdf', [ExportController::class, 'export']);

    //routes budgets

    Route::get('/budgets/summary', [BudgetController::class, 'summary']);
    Route::get(
    '/export/budgets/pdf',
    [BudgetExportController::class, 'export']
);
     Route::apiResource('budgets', BudgetController::class);
     //route dashboard
     Route::get('/dashboard/categories', [DashboardController::class, 'categories']);
     Route::get('/dashboard/evolution', [DashboardController::class, 'evolution']);
     Route::get('/dashboard/recent-transactions', [DashboardController::class, 'recentTransactions']);
});




// AUTH3-05 : changement de mot de passe (sécurité du compte, pas une donnée
// métier : pas de « verified », une session non vérifiée peut protéger son
// compte). Session + CSRF (pile stateful), inactivité, limiteur dédié.
Route::middleware(['auth:sanctum', 'activity.check', 'throttle:password-change'])
    ->put('/user/password', [PasswordController::class, 'update']);

//User  protegé par sanctum + contrôle d'inactivité (SEC-009)
Route::middleware(['auth:sanctum', 'activity.check'])->get('/user', function (Request $request) {
    return $request->user();
});
