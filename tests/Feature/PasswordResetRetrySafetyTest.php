<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetEmailJob;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

/*
 * LWS-BE-06A — sûreté des nouvelles tentatives de réinitialisation.
 *
 * Scénario corrigé : tentative 1 réservée, jeton créé, processus interrompu ;
 * la réservation expire (retry_after) alors que le jeton est encore dans la
 * fenêtre d'anti-rafale. La tentative suivante reçoit RESET_THROTTLED : elle
 * doit être relâchée pour toute la fenêtre (puis renvoyer un lien), et la
 * dernière tentative encore bloquée doit échouer de façon visible.
 *
 * Vraie file « database » de la base de test + vrai queue:work + temps
 * contrôlé (aucune attente réelle, aucun SMTP : Notification::fake).
 */
class PasswordResetRetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    private const RETRY_AFTER = 90; // config/queue.php (connexion database)

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t0 = Carbon::parse('2026-10-17 10:00:00');
        $this->travelTo($this->t0);
    }

    private function runQueueWorker(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->run();
    }

    private function at(int $seconds): void
    {
        $this->travelTo($this->t0->copy()->addSeconds($seconds));
    }

    /**
     * Tentative 1 interrompue : tâche réservée à t0 (attempts = 1), jeton créé
     * à t0 + $tokenAt, puis plus rien (processus tué : ni catch ni nettoyage).
     */
    private function interruptedFirstAttempt(User $user, int $tokenAt = 40, ?string $broker = null): string
    {
        Bus::dispatch(new SendPasswordResetEmailJob($user->id));
        DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => $this->t0->getTimestamp()]);

        $this->at($tokenAt);

        return Password::broker($broker)->createToken($user);
    }

    private function job(): object
    {
        $this->assertSame(1, DB::table('jobs')->count(), 'exactly one queued reset job');

        return DB::table('jobs')->first();
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

    private function useBrokerReturning(string $status): void
    {
        $broker = Mockery::mock(PasswordBroker::class);
        $broker->shouldReceive('sendResetLink')->once()->andReturn($status);
        Password::shouldReceive('broker')->with(config('auth.defaults.passwords'))->andReturn($broker);
    }

    // ---------------------------------------------------------------- T01 : déduplication

    public function test_t01_first_attempt_with_a_recent_token_keeps_the_deduplication(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        Password::broker()->createToken($user);
        $createdAt = DB::table('password_reset_tokens')->value('created_at');

        Bus::dispatch(new SendPasswordResetEmailJob($user->id));
        $this->at(5);
        $this->runQueueWorker();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count(), 'normal completion');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame($createdAt, DB::table('password_reset_tokens')->value('created_at'), 'existing token untouched');
    }

    // ---------------------------------------------------------------- T02 / T05 / T06 : relâche puis renvoi

    public function test_t02_t05_t06_retry_inside_the_throttle_is_released_then_the_third_attempt_sends_a_new_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $orphan = $this->interruptedFirstAttempt($user, tokenAt: 40);

        // Réservation expirée : éligible à t0 + retry_after ; jeton âgé de 50 s.
        $this->at(self::RETRY_AFTER);
        $this->runQueueWorker();

        Notification::assertNothingSent();
        $job = $this->job();
        $this->assertSame(2, (int) $job->attempts, 'the second reservation consumed an attempt, the release kept it');
        $this->assertNull($job->reserved_at);
        $this->assertSame($this->t0->getTimestamp() + self::RETRY_AFTER + 60, (int) $job->available_at, 'released for the full configured window');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'not a failure, not a silent success');

        // Juste avant la fin de la fenêtre : rien n'est disponible.
        $this->at(self::RETRY_AFTER + 59);
        $this->runQueueWorker();
        $this->assertSame(2, (int) $this->job()->attempts);
        Notification::assertNothingSent();

        // Tentative 3 : hors fenêtre, un nouveau lien est créé et envoyé.
        $this->at(self::RETRY_AFTER + 60);
        $this->runQueueWorker();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $sent = $this->sentResetToken($user);
        $this->assertTrue(Password::broker()->tokenExists($user, $sent), 'the replacement link works');
        $this->assertFalse(Password::broker()->tokenExists($user, $orphan), 'only the latest link stays valid');
    }

    // ---------------------------------------------------------------- T03 / T04 : fenêtre du broker réellement utilisé

    public function test_t03_the_release_delay_comes_from_the_resolved_default_broker(): void
    {
        Notification::fake();
        config([
            'auth.passwords.members' => array_merge(config('auth.passwords.users'), ['throttle' => 90]),
            'auth.defaults.passwords' => 'members',
        ]);
        $user = User::factory()->create();
        $this->interruptedFirstAttempt($user, tokenAt: 40, broker: 'members');

        $this->at(self::RETRY_AFTER);
        $this->runQueueWorker();

        $this->assertSame($this->t0->getTimestamp() + self::RETRY_AFTER + 90, (int) $this->job()->available_at);
        Notification::assertNothingSent();
    }

    public function test_t04_a_throttle_other_than_sixty_is_honoured(): void
    {
        Notification::fake();
        config(['auth.passwords.users.throttle' => 120]);
        $user = User::factory()->create();
        $this->interruptedFirstAttempt($user, tokenAt: 40);

        $this->at(self::RETRY_AFTER);
        $this->runQueueWorker();

        $this->assertSame($this->t0->getTimestamp() + self::RETRY_AFTER + 120, (int) $this->job()->available_at);

        $this->at(self::RETRY_AFTER + 120);
        $this->runQueueWorker();

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // ---------------------------------------------------------------- T07 / T08 / T21 : dernière tentative

    public function test_t07_t08_t21_last_attempt_still_throttled_fails_visibly_and_is_never_retried_again(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->interruptedFirstAttempt($user, tokenAt: 40);

        $this->at(self::RETRY_AFTER);
        $this->runQueueWorker(); // tentative 2 : relâchée

        // Une autre demande crée un jeton plus récent avant la tentative 3.
        $this->at(self::RETRY_AFTER + 60);
        $newer = Password::broker()->createToken($user);
        $this->runQueueWorker(); // tentative 3 = dernière : encore bloquée

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count(), 'queue row removed by the failure path');
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'recorded through the queue:work failure listener');

        $exception = (string) DB::table('failed_jobs')->value('exception');
        $this->assertStringContainsString('Password reset still throttled on the final attempt.', $exception);
        $this->assertStringNotContainsString($user->email, $exception);
        $this->assertStringNotContainsString($newer, $exception);

        // T08 : aucune nouvelle remise en file ni 4e traitement.
        $this->at(3600);
        $this->runQueueWorker();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- T09 / T15 : autres statuts du broker

    public function test_t09_an_unexpected_broker_status_is_a_bounded_terminal_failure(): void
    {
        $user = User::factory()->create(['email' => 'unexpected-status@example.test']);
        $this->useBrokerReturning('passwords.unexpected');

        Bus::dispatch(new SendPasswordResetEmailJob($user->id));
        $this->runQueueWorker();

        $this->assertSame(0, DB::table('jobs')->count(), 'no retry loop');
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $exception = (string) DB::table('failed_jobs')->value('exception');
        $this->assertStringContainsString('Unexpected password reset broker status.', $exception);
        $this->assertStringNotContainsString('unexpected-status@example.test', $exception);
    }

    public function test_t15_invalid_user_ends_neutrally(): void
    {
        $user = User::factory()->create();
        $this->useBrokerReturning(PasswordBroker::INVALID_USER);

        Bus::dispatch(new SendPasswordResetEmailJob($user->id));
        $this->runQueueWorker();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // ---------------------------------------------------------------- T10 : hors contexte de file

    public function test_t10_failure_branches_never_look_successful_without_a_queue_job(): void
    {
        $user = User::factory()->create();
        $this->useBrokerReturning('passwords.unexpected');

        $job = new SendPasswordResetEmailJob($user->id);
        $this->assertNull($job->job, 'invoked directly: no queue job attached');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unexpected password reset broker status.');
        $job->handle();
    }

    public function test_t10_throttled_without_a_queue_job_is_only_the_first_attempt_deduplication(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        Password::broker()->createToken($user);

        // attempts() vaut 1 sans tâche attachée : aucune branche de remise en file
        // ni d'échec n'est atteignable, seule la déduplication s'applique.
        (new SendPasswordResetEmailJob($user->id))->handle();

        Notification::assertNothingSent();
    }

    public function test_invalid_broker_configuration_is_refused_without_sending(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        foreach ([
            ['auth.defaults.passwords' => null],
            ['auth.defaults.passwords' => 'missing-broker'],
            ['auth.passwords.users.throttle' => -1],
            ['auth.passwords.users.throttle' => 'sixty'],
        ] as $invalid) {
            $original = [
                'auth.defaults.passwords' => config('auth.defaults.passwords'),
                'auth.passwords.users.throttle' => config('auth.passwords.users.throttle'),
            ];
            config($invalid);

            try {
                (new SendPasswordResetEmailJob($user->id))->handle();
                $this->fail('invalid configuration must not be accepted: '.json_encode($invalid));
            } catch (UnexpectedValueException $e) {
                $this->assertSame('Invalid password reset broker configuration.', $e->getMessage());
            } finally {
                config($original);
            }
        }

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    // ---------------------------------------------------------------- T17 / T18 / T19 : contrat de file inchangé

    public function test_t17_t18_t19_queue_contract_is_unchanged(): void
    {
        $user = User::factory()->create();
        Bus::dispatch(new SendPasswordResetEmailJob($user->id));

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row, 'pushed to the database connection even though the default is sync');
        $this->assertSame('default', $row->queue);

        $payload = json_decode($row->payload, true);
        $this->assertSame(3, $payload['maxTries']);
        $this->assertEquals(10, $payload['backoff']);
        $this->assertNull($payload['timeout'], 'no job-level timeout (deferred)');
        $this->assertNull($payload['retryUntil'] ?? null, 'no retryUntil (deferred)');
    }
}
