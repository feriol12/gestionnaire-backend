<?php

namespace App\Providers;

use App\Http\Middleware\SelectAdminContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

final class AdminFoundationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Kernel::class)->prependToMiddlewarePriority(SelectAdminContext::class);
        // Lot 1's unconditional gate remains untouched. Lot 2 is opt-in and has
        // its own real MFA gate; a cached route also checks activation at runtime.
        if (config('admin.mfa_enabled') === true) {
            $this->loadRoutesFrom(base_path('routes/admin.php'));
        }
    }
}
