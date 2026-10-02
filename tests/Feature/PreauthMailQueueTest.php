<?php

namespace Tests\Feature;

use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordResetController;
use App\Jobs\SendPasswordResetEmailJob;
use App\Jobs\SendVerificationEmailJob;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Timebox;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/*
 * PREAUTH-MAIL-01 — emails d'authentification publics (lien de réinitialisation,
 * renvoi de vérification) traités par des tâches en file « database » dont la
 * charge utile ne contient que l'identifiant du compte (ou null).
 *
 * La requête HTTP ne crée ni jeton, ni hachage bcrypt, ni mail, ni connexion
 * SMTP : elle cherche le compte, met UNE tâche en file dans tous les cas et
 * répond la même chose. La vraie file (table jobs) et un vrai worker sont
 * utilisés ici, en plus de Queue::fake pour les assertions de dispatch.
 */
class PreauthMailQueueTest extends TestCase
{
    use RefreshDatabase;

    private function forgot(string $email)
    {
        return $this->postJson('/api/forgot-password', ['email' => $email]);
    }

    private function resend(string $email)
    {
        return $this->postJson('/api/email/verification-notification', ['email' => $email]);
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

    private function sentResetToken(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        return $token;
    }

    // ---------------------------------------------------------------- forgot-password: HTTP

    public function test_forgot_known_and_unknown_get_the_same_response_and_each_queue_one_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $known = $this->forgot($user->email);
        $unknown = $this->forgot('nobody@example.test');

        foreach ([$known, $unknown] as $response) {
            $response->assertOk()->assertExactJson(['message' => PasswordResetController::LINK_REQUESTED]);
        }
        $this->assertSame($known->getContent(), $unknown->getContent());

        Queue::assertPushed(SendPasswordResetEmailJob::class, 2);
        Queue::assertPushed(SendPasswordResetEmailJob::class, fn ($job) => $job->userId === $user->id);
        Queue::assertPushed(SendPasswordResetEmailJob::class, fn ($job) => $job->userId === null);
        $this->assertGuest('web');
    }

    public function test_the_forgot_request_itself_creates_no_token_and_sends_nothing(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->forgot($user->email)->assertOk();

        $this->assertSame(0, DB::table('password_reset_tokens')->count(), 'no token (no bcrypt) in the HTTP request');
        Notification::assertNothingSent();
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_jobs_use_the_database_connection_even_when_the_default_is_sync(): void
    {
        Notification::fake();
        config(['queue.default' => 'sync']);
        $user = User::factory()->unverified()->create();

        $this->forgot($user->email)->assertOk();
        $this->resend($user->email)->assertOk();

        $this->assertSame(2, DB::table('jobs')->count(), 'queued in the jobs table, not run synchronously');
        Notification::assertNothingSent();
        foreach ([SendPasswordResetEmailJob::class, SendVerificationEmailJob::class] as $class) {
            $attributes = (new \ReflectionClass($class))->getAttributes(Connection::class);
            $this->assertSame('database', $attributes[0]->newInstance()->connection);
        }
    }

    public function test_the_public_request_runs_inside_the_native_timebox(): void
    {
        Queue::fake();
        $calls = [];
        $this->app->bind(Timebox::class, function () use (&$calls) {
            return new class($calls) extends Timebox
            {
                private array $calls;

                public function __construct(array &$calls)
                {
                    $this->calls = &$calls;
                }

                public function call(callable $callback, int $microseconds)
                {
                    $this->calls[] = $microseconds;

                    return $callback($this);
                }
            };
        });

        $this->forgot('nobody@example.test')->assertOk();
        $this->resend('nobody@example.test')->assertOk();

        $this->assertSame([200000, 200000], $calls);
        Queue::assertPushed(SendPasswordResetEmailJob::class, 1);
        Queue::assertPushed(SendVerificationEmailJob::class, 1);
    }

    // ---------------------------------------------------------------- forgot-password: worker

    public function test_the_worker_creates_the_token_and_sends_a_valid_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->forgot($user->email)->assertOk();
        $this->runQueueWorker();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->assertTrue(Password::broker()->tokenExists($user, $this->sentResetToken($user)));
    }

    public function test_null_or_missing_user_jobs_do_nothing(): void
    {
        Notification::fake();

        (new SendPasswordResetEmailJob(null))->handle();
        (new SendPasswordResetEmailJob(987654))->handle();
        (new SendVerificationEmailJob(null))->handle();
        (new SendVerificationEmailJob(987654))->handle();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_duplicate_requests_respect_the_broker_throttle(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->forgot($user->email)->assertOk();
        $this->forgot($user->email)->assertOk();
        $this->assertSame(2, DB::table('jobs')->count());

        $this->runQueueWorker();

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_a_mail_failure_removes_the_unsent_token_so_the_retry_can_send_a_valid_link(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        try {
            (new SendPasswordResetEmailJob($user->id))->handle();
            $this->fail('the mail failure must propagate so the queue retries the job');
        } catch (TransportException) {
            // expected: the job fails and will be retried by the queue
        }

        $this->assertSame(0, DB::table('password_reset_tokens')->count(), 'no live token left behind by the failed attempt');

        // Retry (well inside the broker's 60 s throttle window): a new valid link.
        Notification::fake();
        (new SendPasswordResetEmailJob($user->id))->handle();

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->assertTrue(Password::broker()->tokenExists($user, $this->sentResetToken($user)));
    }

    public function test_a_mail_failure_through_the_real_queue_releases_the_job_for_retry(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->forgot($user->email)->assertOk();
        $this->runQueueWorker();

        $job = DB::table('jobs')->first();
        $this->assertNotNull($job, 'released for a later attempt (Tries 3, Backoff 10 s)');
        $this->assertSame(1, (int) $job->attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    // ---------------------------------------------------------------- verification resend

    public function test_resend_gets_the_same_response_and_queues_one_job_in_every_case(): void
    {
        Queue::fake();
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        $responses = [
            $this->resend($unverified->email),
            $this->resend($verified->email),
            $this->resend('nobody@example.test'),
        ];

        foreach ($responses as $response) {
            $response->assertOk()->assertExactJson(['message' => EmailVerificationController::RESEND_REQUESTED]);
            $this->assertSame($responses[0]->getContent(), $response->getContent());
        }

        Queue::assertPushed(SendVerificationEmailJob::class, 3);
        Queue::assertPushed(SendVerificationEmailJob::class, fn ($job) => $job->userId === $unverified->id);
        Queue::assertPushed(SendVerificationEmailJob::class, fn ($job) => $job->userId === $verified->id);
        Queue::assertPushed(SendVerificationEmailJob::class, fn ($job) => $job->userId === null);
    }

    public function test_the_worker_sends_only_to_an_existing_unverified_account(): void
    {
        Notification::fake();
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        $this->resend($unverified->email)->assertOk();
        $this->resend($verified->email)->assertOk();
        $this->resend('nobody@example.test')->assertOk();
        Notification::assertNothingSent();

        $this->runQueueWorker();

        $this->assertSame(0, DB::table('jobs')->count());
        Notification::assertSentToTimes($unverified, VerifyEmail::class, 1);
        Notification::assertNotSentTo($verified, VerifyEmail::class);
        Notification::assertCount(1);
    }

    public function test_eligibility_is_checked_again_when_the_job_runs(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->resend($user->email)->assertOk();
        $user->markEmailAsVerified(); // verified between the request and the worker

        $this->runQueueWorker();

        Notification::assertNothingSent();
    }

    public function test_the_link_sent_by_the_worker_verifies_without_logging_in(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->resend($user->email)->assertOk();
        $this->runQueueWorker();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->get($url)->assertRedirect(config('app.frontend_url').'/login?email_verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------- limiters

    public function test_limiters_are_unchanged_and_a_throttled_request_queues_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->forgot($user->email)->assertOk();
            $this->resend($user->email)->assertOk();
        }
        $this->forgot($user->email)->assertStatus(429);
        $this->resend($user->email)->assertStatus(429);

        Queue::assertPushed(SendPasswordResetEmailJob::class, 3);
        Queue::assertPushed(SendVerificationEmailJob::class, 3);
    }

    // ---------------------------------------------------------------- queue failure

    public function test_a_queue_insert_failure_keeps_the_public_response_neutral(): void
    {
        Exceptions::fake();
        Notification::fake();
        config(['queue.connections.database.table' => 'missing_jobs_table']);
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        $forgot = [$this->forgot($unverified->email), $this->forgot('nobody@example.test')];
        $resend = [$this->resend($unverified->email), $this->resend($verified->email), $this->resend('nobody@example.test')];

        foreach ($forgot as $response) {
            $response->assertOk()->assertExactJson(['message' => PasswordResetController::LINK_REQUESTED]);
        }
        foreach ($resend as $response) {
            $response->assertOk()->assertExactJson(['message' => EmailVerificationController::RESEND_REQUESTED]);
        }

        Exceptions::assertReported(QueryException::class);
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    // ---------------------------------------------------------------- payload security

    public function test_queued_payloads_carry_only_the_user_id(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'payload-check@example.test']);

        $this->forgot($user->email)->assertOk();
        $this->resend($user->email)->assertOk();
        $this->forgot('unknown-payload@example.test')->assertOk();

        $payloads = DB::table('jobs')->orderBy('id')->pluck('payload');
        $this->assertCount(3, $payloads);

        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString('payload-check', $payload);
            $this->assertStringNotContainsString('unknown-payload', $payload);
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $payload, 'no email address');
            $this->assertStringNotContainsString('reset-password', $payload);
            $this->assertStringNotContainsString('signature', $payload);
            $this->assertStringNotContainsString('http', $payload, 'no URL');
            $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $payload, 'no reset token');
        }

        [$reset, $verify, $unknown] = $this->queuedCommands();
        $this->assertInstanceOf(SendPasswordResetEmailJob::class, $reset);
        $this->assertInstanceOf(SendVerificationEmailJob::class, $verify);
        $this->assertSame($user->id, $reset->userId);
        $this->assertSame($user->id, $verify->userId);
        $this->assertNull($unknown->userId);

        // The job classes declare no business property other than userId.
        foreach ([SendPasswordResetEmailJob::class, SendVerificationEmailJob::class] as $class) {
            $own = array_values(array_filter(
                (new \ReflectionClass($class))->getProperties(),
                fn (\ReflectionProperty $p) => $p->getDeclaringClass()->getName() === $class && ! in_array($p->getName(), $this->traitProperties(), true)
            ));
            $this->assertSame(['userId'], array_map(fn ($p) => $p->getName(), $own));
        }
    }

    private function traitProperties(): array
    {
        $names = [];
        foreach (class_uses_recursive(SendPasswordResetEmailJob::class) as $trait) {
            foreach ((new \ReflectionClass($trait))->getProperties() as $p) {
                $names[] = $p->getName();
            }
        }

        return $names;
    }
}
