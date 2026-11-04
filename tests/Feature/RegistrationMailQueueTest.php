<?php

namespace Tests\Feature;

use App\Http\Controllers\EmailVerificationController;
use App\Jobs\SendVerificationEmailJob;
use App\Listeners\QueueEmailVerification;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/*
 * REG-MAIL-ASYNC — l'email de vérification initial part par la file « database »
 * (Registered → QueueEmailVerification → SendVerificationEmailJob, la tâche déjà
 * utilisée par le renvoi). La requête d'inscription insère UNE tâche et ne fait
 * ni notification, ni URL signée, ni SMTP. Vraie table jobs et vrai worker.
 */
class RegistrationMailQueueTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-password-123';

    private function register(string $email)
    {
        return $this->postJson('/api/register', [
            'name' => 'Queued User', 'email' => $email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);
    }

    private function runQueueWorker(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->run();
    }

    private function queuedCommands(): array
    {
        return DB::table('jobs')->orderBy('id')->pluck('payload')
            ->map(fn (string $payload) => unserialize(json_decode($payload, true)['data']['command']))
            ->all();
    }

    private function unreachableSmtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
    }

    // Compte les notifications réellement préparées (sans les simuler).
    private function countNotificationsSending(): \ArrayObject
    {
        $count = new \ArrayObject(['n' => 0]);
        Event::listen(NotificationSending::class, function () use ($count) {
            $count['n']++;
        });

        return $count;
    }

    private function sentVerificationUrl(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        return $url;
    }

    /**
     * Listeners of an event, normalized to class names whatever the way Laravel
     * stored them (class string, "Class@method", [Class, method], object, closure).
     */
    private function listenerNames(string $event): array
    {
        return array_map(function ($listener) {
            if (is_string($listener)) {
                return explode('@', $listener)[0];
            }
            if (is_array($listener)) {
                return is_object($listener[0]) ? get_class($listener[0]) : $listener[0];
            }

            return get_class($listener);
        }, Event::getRawListeners()[$event] ?? []);
    }

    // ---------------------------------------------------------------- T10 / T12. topology

    public function test_registered_has_exactly_one_listener_the_queue_listener_and_not_the_native_one(): void
    {
        $this->assertSame([QueueEmailVerification::class], $this->listenerNames(Registered::class));
        $this->assertNotContains(SendEmailVerificationNotification::class, $this->listenerNames(Registered::class));
        $this->assertCount(1, Event::getListeners(Registered::class), 'one effective (wrapped) listener');

        // Effet observable : l'événement seul met une tâche en file et n'envoie rien.
        Notification::fake();
        $user = User::factory()->unverified()->create();
        event(new Registered($user));

        $this->assertSame(1, DB::table('jobs')->count());
        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- T1 / T3. request

    public function test_registration_queues_exactly_one_job_and_sends_nothing_during_the_request(): void
    {
        Notification::fake();

        $this->register('queued@example.test')->assertOk();

        $user = User::where('email', 'queued@example.test')->firstOrFail();
        $this->assertSame(1, User::count());
        Notification::assertNothingSent();

        $commands = $this->queuedCommands();
        $this->assertCount(1, $commands);
        $this->assertInstanceOf(SendVerificationEmailJob::class, $commands[0]);
        $this->assertSame($user->id, $commands[0]->userId);
    }

    public function test_registration_queues_through_the_bus_once(): void
    {
        Queue::fake();

        $this->register('bus@example.test')->assertOk();

        $user = User::where('email', 'bus@example.test')->firstOrFail();
        Queue::assertPushed(SendVerificationEmailJob::class, 1);
        Queue::assertPushed(SendVerificationEmailJob::class, fn ($job) => $job->userId === $user->id);
    }

    public function test_the_registration_http_contract_is_unchanged(): void
    {
        $response = $this->register('contract@example.test');

        $response->assertOk()
            ->assertJsonPath('email', 'contract@example.test')
            ->assertJsonPath('name', 'Queued User')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token');
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['created_at', 'email', 'id', 'name', 'updated_at'], $keys);

        $user = User::where('email', 'contract@example.test')->firstOrFail();
        $this->assertSame($user->id, $response->json('id'));
        $this->assertNull($user->email_verified_at);
        $this->assertGuest('web');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_registration_request_never_touches_smtp(): void
    {
        Exceptions::fake();
        $this->unreachableSmtp();
        $sending = $this->countNotificationsSending();

        $this->register('no-smtp@example.test')->assertOk();

        Exceptions::assertNothingReported();
        $this->assertSame(0, $sending['n'], 'no notification prepared in the request');
        $this->assertSame(1, DB::table('jobs')->count());
    }

    // ---------------------------------------------------------------- T2. database pinning

    public function test_the_job_goes_to_the_database_queue_even_when_the_default_is_sync(): void
    {
        Notification::fake();
        config(['queue.default' => 'sync']);

        $this->register('pinned@example.test')->assertOk();

        $this->assertSame(1, DB::table('jobs')->count(), 'queued in the jobs table, not run synchronously');
        Notification::assertNothingSent();
        $attributes = (new \ReflectionClass(SendVerificationEmailJob::class))->getAttributes(Connection::class);
        $this->assertSame('database', $attributes[0]->newInstance()->connection);
    }

    // ---------------------------------------------------------------- T4 / T12. worker

    public function test_the_real_worker_sends_exactly_one_verification_email(): void
    {
        Notification::fake();

        $this->register('worker@example.test')->assertOk();
        $user = User::where('email', 'worker@example.test')->firstOrFail();

        $this->assertSame(1, DB::table('jobs')->count());
        Notification::assertNothingSent();

        $this->runQueueWorker();

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        Notification::assertCount(1);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_one_registration_never_produces_a_second_initial_email(): void
    {
        Notification::fake();

        $this->register('once@example.test')->assertOk();
        $user = User::where('email', 'once@example.test')->firstOrFail();

        $this->runQueueWorker();
        $this->runQueueWorker(); // nothing left to process

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        Notification::assertCount(1);
    }

    // ---------------------------------------------------------------- T5. link

    public function test_the_link_sent_by_the_worker_keeps_the_verification_url_semantics(): void
    {
        Notification::fake();

        $this->register('link@example.test')->assertOk();
        $user = User::where('email', 'link@example.test')->firstOrFail();
        $this->runQueueWorker();

        $url = $this->sentVerificationUrl($user);
        $prefix = rtrim((string) config('app.url'), '/').'/api/email/verify/'.$user->id.'/'.sha1($user->email).'?';
        $this->assertStringStartsWith($prefix, $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);

        $this->get($url)->assertRedirect(config('app.frontend_url').'/login?email_verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------- T6. payload and link timing

    public function test_the_queued_payload_carries_only_the_user_id(): void
    {
        Notification::fake();

        $this->register('payload-check@example.test')->assertOk();
        $user = User::where('email', 'payload-check@example.test')->firstOrFail();

        $payloads = DB::table('jobs')->pluck('payload');
        $this->assertCount(1, $payloads);
        $payload = $payloads[0];

        $this->assertStringNotContainsString('payload-check', $payload);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $payload, 'no email address');
        $this->assertStringNotContainsString(sha1($user->email), $payload, 'no email hash');
        $this->assertStringNotContainsString('http', $payload, 'no URL');
        $this->assertStringNotContainsString('email/verify', $payload);
        $this->assertStringNotContainsString('signature', $payload);
        $this->assertStringNotContainsString('expires', $payload);
        $this->assertStringNotContainsString('Queued User', $payload);

        [$command] = $this->queuedCommands();
        $this->assertSame($user->id, $command->userId);

        $own = array_values(array_filter(
            (new \ReflectionClass(SendVerificationEmailJob::class))->getProperties(),
            fn (\ReflectionProperty $p) => $p->getDeclaringClass()->getName() === SendVerificationEmailJob::class && ! in_array($p->getName(), $this->traitProperties(), true)
        ));
        $this->assertSame(['userId'], array_map(fn ($p) => $p->getName(), $own));
    }

    public function test_the_signed_link_is_generated_by_the_worker_mail_rendering_not_by_the_request(): void
    {
        // Vrai canal mail (transport local « array », aucun réseau) et vrai callback
        // d'URL de l'application, observé sans être remplacé : Notification::fake
        // n'exécute pas toMail() et ne prouverait pas le moment de génération.
        $original = VerifyEmail::$createUrlCallback;
        $this->assertIsCallable($original, 'AuthServiceProvider must install the verification URL callback');

        $calls = 0;
        $generatedUrl = null;
        VerifyEmail::createUrlUsing(function ($notifiable) use (&$calls, &$generatedUrl, $original) {
            $calls++;
            $generatedUrl = $original($notifiable);

            return $generatedUrl;
        });

        try {
            config(['mail.default' => 'array']);
            $expire = (int) config('auth.verification.expire', 60);
            $this->travelTo(now()->startOfMinute());

            $this->register('timing@example.test')->assertOk();
            $user = User::where('email', 'timing@example.test')->firstOrFail();

            // The registration request generated no URL.
            $this->assertSame(1, DB::table('jobs')->count());
            $this->assertSame(0, $calls, 'no verification URL generated by the registration request');
            $this->assertNull($generatedUrl);

            $this->travel(30)->minutes();
            $workerRanAt = now()->getTimestamp();
            $this->runQueueWorker();

            // Generated exactly once, while the worker rendered the mail.
            $this->assertSame(0, DB::table('jobs')->count());
            $this->assertSame(1, $calls, 'verification URL generated once, by the worker mail rendering');
            $this->assertIsString($generatedUrl);
            $this->assertNotSame('', $generatedUrl);

            $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
            $this->assertCount(1, $messages);
            $email = $messages[0]->getOriginalMessage();
            $this->assertSame([$user->email], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
            $this->assertStringContainsString(e($generatedUrl), (string) $email->getHtmlBody(), 'the rendered mail carries the generated link');

            // Inspected later, at another time: the captured link keeps the worker-time expiry.
            $this->travel(5)->minutes();
            $prefix = rtrim((string) config('app.url'), '/').'/api/email/verify/'.$user->id.'/'.sha1($user->email).'?';
            $this->assertStringStartsWith($prefix, $generatedUrl);
            $this->assertStringContainsString('expires=', $generatedUrl);
            $this->assertStringContainsString('signature=', $generatedUrl);
            parse_str((string) parse_url($generatedUrl, PHP_URL_QUERY), $query);
            $this->assertSame($workerRanAt + $expire * 60, (int) $query['expires'], 'expiry counted from the worker run');

            // Past the expiry a request-time link would have had, still valid.
            $this->travel($expire - 15)->minutes();
            $this->get($generatedUrl)->assertRedirect(config('app.frontend_url').'/login?email_verified=1');
            $this->assertTrue($user->fresh()->hasVerifiedEmail());
            $this->assertGuest('web');
        } finally {
            VerifyEmail::createUrlUsing($original);
        }

        $this->assertSame($original, VerifyEmail::$createUrlCallback, 'static callback restored');
    }

    // ---------------------------------------------------------------- T7 / T8. eligibility at run time

    public function test_a_user_verified_before_the_worker_runs_receives_nothing(): void
    {
        Notification::fake();

        $this->register('verified-meanwhile@example.test')->assertOk();
        User::where('email', 'verified-meanwhile@example.test')->firstOrFail()->markEmailAsVerified();

        $this->runQueueWorker();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_user_deleted_before_the_worker_runs_receives_nothing(): void
    {
        Notification::fake();

        $this->register('deleted-meanwhile@example.test')->assertOk();
        User::where('email', 'deleted-meanwhile@example.test')->firstOrFail()->delete();

        $this->runQueueWorker();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // ---------------------------------------------------------------- T9. queue insert failure

    public function test_a_queue_insert_failure_keeps_the_account_and_the_success_contract_without_fallback(): void
    {
        Exceptions::fake();
        $this->unreachableSmtp();
        $sending = $this->countNotificationsSending();
        config(['queue.connections.database.table' => 'missing_jobs_table']);

        $response = $this->register('queue-down@example.test');

        $response->assertOk()->assertJsonPath('email', 'queue-down@example.test')->assertJsonMissingPath('token');
        $this->assertStringNotContainsStringIgnoringCase('missing_jobs_table', $response->getContent());
        $this->assertDatabaseHas('users', ['email' => 'queue-down@example.test', 'email_verified_at' => null]);
        Exceptions::assertReported(QueryException::class);
        Exceptions::assertNotReported(TransportException::class);
        $this->assertSame(0, $sending['n'], 'no synchronous fallback');
        $this->assertSame(0, DB::table('jobs')->count());

        // Queue restored (the manager caches resolved connections).
        config(['queue.connections.database.table' => 'jobs']);
        $this->app->forgetInstance('queue');
        Queue::clearResolvedInstance('queue');

        // The account exists: registering again is refused; resend is the recovery path.
        $this->register('queue-down@example.test')->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/email/verification-notification', ['email' => 'queue-down@example.test'])
            ->assertOk()->assertExactJson(['message' => EmailVerificationController::RESEND_REQUESTED]);
        $this->assertSame(1, DB::table('jobs')->count(), 'the restored queue accepts the resend job');
        $this->assertSame(1, User::where('email', 'queue-down@example.test')->count(), 'no compensation, no duplicate');
    }

    // ---------------------------------------------------------------- T11. retry metadata

    public function test_the_reused_job_keeps_its_queue_and_retry_policy(): void
    {
        $class = new \ReflectionClass(SendVerificationEmailJob::class);

        $this->assertSame('database', $class->getAttributes(Connection::class)[0]->newInstance()->connection);
        $this->assertSame(3, $class->getAttributes(Tries::class)[0]->newInstance()->tries);
        $this->assertSame(10, $class->getAttributes(Backoff::class)[0]->newInstance()->backoff);
    }

    public function test_an_smtp_failure_in_the_worker_releases_the_job_for_retry(): void
    {
        $this->unreachableSmtp();

        $this->register('smtp-down@example.test')->assertOk();
        $this->runQueueWorker();

        $job = DB::table('jobs')->first();
        $this->assertNotNull($job, 'released for a later attempt (Tries 3, Backoff 10 s)');
        $this->assertSame(1, (int) $job->attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertDatabaseHas('users', ['email' => 'smtp-down@example.test', 'email_verified_at' => null]);
    }

    private function traitProperties(): array
    {
        $names = [];
        foreach (class_uses_recursive(SendVerificationEmailJob::class) as $trait) {
            foreach ((new \ReflectionClass($trait))->getProperties() as $p) {
                $names[] = $p->getName();
            }
        }

        return $names;
    }
}
