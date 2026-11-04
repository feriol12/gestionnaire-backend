<?php

namespace App\Listeners;

use App\Jobs\SendVerificationEmailJob;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Bus;

/*
 * REG-MAIL-ASYNC : remplace le listener natif synchrone
 * SendEmailVerificationNotification. L'inscription ne fait qu'insérer UNE tâche
 * en file « database » (identifiant du compte uniquement) ; l'éligibilité, l'URL
 * signée et le SMTP sont dans le worker (SendVerificationEmailJob, partagé avec
 * le renvoi). Bus::dispatch insère immédiatement : un échec d'insertion remonte
 * ici, donc dans le try/catch de AuthController::register.
 */
class QueueEmailVerification
{
    public function handle(Registered $event): void
    {
        if ($event->user instanceof MustVerifyEmail && ! $event->user->hasVerifiedEmail()) {
            Bus::dispatch(new SendVerificationEmailJob($event->user->getKey()));
        }
    }
}
