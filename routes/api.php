<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExpenseController;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BudgetExportController;
use App\Http\Controllers\DashboardController;

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
// Protégez la route logout avec Sanctum
// Volontairement SANS activity.check : un jeton inactif doit pouvoir être révoqué.
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
// Route::post('/logout', [AuthController::class, 'logout']);

    //routes expenses

Route::middleware(['auth:sanctum', 'activity.check'])->group(function () {
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




//User  protegé par sanctum + contrôle d'inactivité (SEC-009)
Route::middleware(['auth:sanctum', 'activity.check'])->get('/user', function (Request $request) {
    return $request->user();
});
