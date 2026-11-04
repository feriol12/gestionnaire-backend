<?php

namespace Tests\AdminFoundation;

use App\Http\Middleware\AdminCsrf;
use App\Http\Middleware\SelectAdminContext;
use App\Models\AdminIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** A PHP-only harness capability, never an environment/configuration toggle. */
final class FixtureCapability
{
    public static function assertAllowed(): void
    {
        if (!app()->bound(self::class) || !app()->environment('admin-foundation-test')) {
            throw new \LogicException('Administrative fixtures require the isolated test harness.');
        }
    }
}

final class ObservedClientIdentity
{
    public function handle($request,$next)
    {
        $describe=static fn($user)=>['type'=>$user ? get_class($user) : null,'id'=>$user?->getAuthIdentifier()];
        app()->instance('foundation.principal',[
            'guard'=>Auth::getDefaultDriver(),
            'request'=>$describe($request->user()),
            'sanctum'=>$describe(Auth::guard('sanctum')->user()),
            'ambient'=>$describe(Auth::user()),
        ]);
        return (new \App\Http\Middleware\RequireClientIdentity())->handle($request,$next);
    }
}

/** Test-only session seeding. Never loaded by any production provider or route file. */
final class FixtureController
{
    public function __construct() { FixtureCapability::assertAllowed(); }
    public function csrf(Request $request) { return response()->json(['csrf'=>$request->session()->token()]); }
    public function seed(Request $request) {
        Auth::guard('admin')->login(AdminIdentity::findOrFail(1));
        $request->session()->put('mfa_complete', true); // Hostile/forged claim must NOT bypass production gate.
        $request->session()->regenerate();
        return response()->json(['csrf'=>$request->session()->token()]);
    }
    public function logout(Request $request) {
        Auth::guard('admin')->logout();$request->session()->invalidate();$request->session()->regenerateToken();
        return response()->json(['csrf'=>$request->session()->token()]);
    }
    public function who() { return response()->json(['type'=>Auth::guard('admin')->user() ? get_class(Auth::guard('admin')->user()) : null,'id'=>Auth::guard('admin')->id()]); }
    public function forbidden() { throw new \LogicException('Production MFA gate was bypassed'); }
    public function error() { abort(422); }
}

final class FixtureProvider extends ServiceProvider
{
    public function boot(): void
    {
        FixtureCapability::assertAllowed();
        $this->app->bind(\App\Http\Middleware\RequireClientIdentity::class,ObservedClientIdentity::class);
        if ($this->app->routesAreCached()) return;
        Route::middleware('admin.foundation')->get('/api/admin/protected',[FixtureController::class,'forbidden']);
        Route::middleware('admin.foundation')->post('/api/admin/mfa',[FixtureController::class,'forbidden']);
        // Infrastructure exercises deliberately omit authorization; test-only, not an authentication flow.
        Route::prefix('api/admin/fixture')->middleware([
            SelectAdminContext::class,\App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,AdminCsrf::class,
        ])->group(function () {
            Route::get('csrf',[FixtureController::class,'csrf']);
            Route::post('seed',[FixtureController::class,'seed']);
            Route::post('login',[FixtureController::class,'seed']); // Test-only HTTP path coverage, not a real login.
            Route::post('logout',[FixtureController::class,'logout']);
            Route::get('who',[FixtureController::class,'who']);
            Route::get('error',[FixtureController::class,'error']);
        });
    }
}
