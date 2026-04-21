<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
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
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
// Protégez la route logout avec Sanctum
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
// Route::post('/logout', [AuthController::class, 'logout']);





//User  protegé par sanctum
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
