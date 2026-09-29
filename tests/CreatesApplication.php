<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\TestDatabaseSafety;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // SEC-AUTH-00: must run here, before this method returns. Laravel's
        // Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle
        // calls createApplication() (via refreshApplication()) and only
        // afterwards calls setUpTraits(), which is what actually triggers
        // RefreshDatabase's migrations. Guarding after this point would be
        // too late.
        //
        // This call is the authoritative safety layer: it reads Laravel's
        // fully resolved config off the booted $app, so it is correct
        // regardless of whether that configuration came from phpunit.xml's
        // defensive env overrides, .env, .env.testing, or the shell.
        TestDatabaseSafety::guardApplication($app);

        return $app;
    }
}
