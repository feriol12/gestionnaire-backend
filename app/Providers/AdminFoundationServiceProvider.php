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
        // Deliberately registers no routes and no administrative login endpoint.
    }
}
