<?php

namespace App\Providers;

use App\Listeners\QueueEmailVerification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            QueueEmailVerification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * REG-MAIL-ASYNC : volontairement vide. Le provider de base réenregistre le
     * listener natif synchrone SendEmailVerificationNotification dès qu'il est
     * absent de $listen ; il enverrait alors l'email pendant la requête, en plus
     * de la tâche en file (QueueEmailVerification).
     */
    protected function configureEmailVerification(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
