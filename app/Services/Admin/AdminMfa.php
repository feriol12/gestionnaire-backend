<?php

namespace App\Services\Admin;

use App\Models\AdminIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** All factor transitions serialize on the singleton; no client User is involved. */
final class AdminMfa
{
    public function __construct(private AdminTotp $totp) {}

    private function event(string $name, ?int $id = 1): void
    {
        DB::table('admin_security_events')->insert(['event' => $name, 'admin_id' => $id, 'created_at' => now()]);
    }

    public function provision(string $email, #[\SensitiveParameter] string $password): void
    {
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($password) >= 16 && strlen($password) <= 128, 422);
        DB::transaction(function () use ($email, $password) {
            // The primary key and CHECK, not an exists() race, enforce the singleton.
            $admin = new AdminIdentity;
            $admin->forceFill(['id' => 1, 'email' => strtolower(trim($email)), 'password' => $password,
                'auth_version' => 1, 'mfa_state' => 'pending_enrollment'])->save();
            $this->event('provisioned_pending');
        });
    }

    /** Independent identity and IP budgets; committed even if validation then fails. */
    private function throttle(Request $r, string $identity): void
    {
        $time = now()->timestamp;
        foreach (['ip:'.(string) $r->ip() => 20, 'identity:'.$identity => 5] as $subject => $limit) {
            $key = hash('sha256', 'admin-auth|'.intdiv($time, 300).'|'.$subject);
            $allowed = DB::transaction(function () use ($key, $time, $limit) {
                DB::table('admin_auth_attempts')->insertOrIgnore(['id' => $key, 'attempts' => 0, 'expires_at' => $time + 600]);
                return DB::table('admin_auth_attempts')->where('id', $key)->where('attempts', '<', $limit)->increment('attempts') === 1;
            });
            if (!$allowed) { $this->event('throttled', null); abort(429, 'ADMIN_AUTH_THROTTLED'); }
        }
    }

    private function identity(): AdminIdentity
    {
        if (DB::getDriverName() === 'sqlite') {
            // Acquire the SQLite writer reservation before reading the singleton.
            DB::table('admin_identities')->where('id', 1)->update(['auth_version' => DB::raw('auth_version')]);
        }
        $admin = AdminIdentity::query()->whereKey(1)->lockForUpdate()->first();
        abort_unless($admin instanceof AdminIdentity, 401, 'ADMIN_AUTH_FAILED');
        return $admin;
    }

    private function challenge(Request $r, AdminIdentity $a): object
    {
        $token = $r->session()->get('admin.challenge');
        abort_unless(is_string($token), 401, 'ADMIN_CHALLENGE_REQUIRED');
        $c = DB::table('admin_mfa_challenges')->where('id', hash('sha256', $token))->first();
        abort_unless($c && (int) $c->admin_id === (int) $a->id
            && (int) $c->auth_version === (int) $a->auth_version && (int) $c->expires_at > now()->timestamp
            && hash_equals($c->session_hash, hash('sha256', $r->session()->getId())), 401, 'ADMIN_CHALLENGE_INVALID');
        return $c;
    }

    private function issueChallenge(Request $r, AdminIdentity $a, string $purpose): void
    {
        $old = $r->session()->get('admin.challenge');
        if (is_string($old)) DB::table('admin_mfa_challenges')->where('id', hash('sha256', $old))->delete();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        $token = bin2hex(random_bytes(32));
        DB::table('admin_mfa_challenges')->insert([
            'id' => hash('sha256', $token), 'admin_id' => $a->id,
            'session_hash' => hash('sha256', $r->session()->getId()), 'auth_version' => $a->auth_version,
            'purpose' => $purpose, 'expires_at' => now()->timestamp + 300,
        ]);
        $r->session()->put('admin.challenge', $token);
    }

    public function login(Request $r, string $email, #[\SensitiveParameter] string $password): void
    {
        $email = strtolower(trim($email));
        $this->throttle($r, 'login:'.$email);
        $a = AdminIdentity::query()->where('email', $email)->first();
        // Fixed dummy hash prevents the absent identity path skipping the expensive check.
        $valid = Hash::check($password, $a?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$a || !$valid) { $this->event('password_refused', null); abort(401, 'ADMIN_AUTH_FAILED'); }
        DB::transaction(function () use ($r, $a) {
            $locked = $this->identity();
            abort_unless(hash_equals($locked->password, $a->password)
                && (int) $locked->auth_version === (int) $a->auth_version, 401);
            // Recovery authorization belongs to its one session-bound challenge.
            // Knowing only the password must not take over a pending recovery.
            abort_if($locked->mfa_state === 'recovery_required', 403, 'ADMIN_RECOVERY_CHALLENGE_REQUIRED');
            abort_unless(in_array($locked->mfa_state, ['pending_enrollment', 'active'], true), 403);
            $this->revokeCurrentGrant($r);
            Auth::guard('admin')->forgetUser();
            $this->issueChallenge($r, $locked, $locked->mfa_state === 'active' ? 'verify' : 'enroll');
            $this->event('password_accepted');
        });
    }

    public function enrollment(Request $r): string
    {
        $this->throttle($r, 'mfa:1');
        return DB::transaction(function () use ($r) {
            $a = $this->identity(); $c = $this->challenge($r, $a);
            abort_unless($c->purpose === 'enroll' && in_array($a->mfa_state, ['pending_enrollment', 'recovery_required'], true), 403);
            abort_unless($c->pending_secret === null, 409, 'ADMIN_ENROLLMENT_ALREADY_ISSUED');
            $secret = $this->totp->secret();
            DB::table('admin_mfa_challenges')->where('id', $c->id)->update(['pending_secret' => Crypt::encryptString($secret)]);
            return $secret; // Only this enrollment response. Never a third-party QR service.
        });
    }

    private function advance(AdminIdentity $a, #[\SensitiveParameter] string $cipher, #[\SensitiveParameter] string $otp, int $last): void
    {
        $step = $this->totp->acceptedStep(Crypt::decryptString($cipher), $otp, $last);
        abort_if($step === null, 401, 'ADMIN_FACTOR_REFUSED');
        // CAS is also required on SQLite, where SELECT FOR UPDATE is ineffective.
        $changed = DB::table('admin_identities')->where('id', $a->id)
            ->where('auth_version', $a->auth_version)->where('totp_last_step', $a->totp_last_step)
            ->update(['totp_last_step' => $step]);
        abort_unless($changed === 1, 401, 'ADMIN_FACTOR_REFUSED');
        $a->totp_last_step = $step;
    }

    private function codes(AdminIdentity $a): array
    {
        DB::table('admin_recovery_codes')->where('admin_id', $a->id)->delete();
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = bin2hex(random_bytes(32));
            DB::table('admin_recovery_codes')->insert(['admin_id' => $a->id, 'digest' => hash('sha256', $code)]);
            $codes[] = $code;
        }
        return $codes;
    }

    private function revokeAll(AdminIdentity $a): void
    {
        $a->auth_version++;
        DB::table('admin_mfa_grants')->where('admin_id', $a->id)->delete();
        DB::table('admin_mfa_challenges')->where('admin_id', $a->id)->delete();
        DB::table('admin_sessions')->where('user_id', $a->id)->delete();
    }

    public function verify(Request $r, #[\SensitiveParameter] string $otp): array
    {
        $this->throttle($r, 'mfa:1');
        return DB::transaction(function () use ($r, $otp) {
            $a = $this->identity(); $c = $this->challenge($r, $a); $codes = [];
            if ($c->purpose === 'enroll') {
                abort_unless(in_array($a->mfa_state, ['pending_enrollment', 'recovery_required'], true) && is_string($c->pending_secret), 403);
                $this->advance($a, $c->pending_secret, $otp, -1);
                $this->revokeAll($a);
                $a->totp_ciphertext = $c->pending_secret; $a->mfa_state = 'active';
                $codes = $this->codes($a);
            } else {
                abort_unless($c->purpose === 'verify' && $a->mfa_state === 'active' && is_string($a->totp_ciphertext), 403);
                $this->advance($a, $a->totp_ciphertext, $otp, (int) $a->totp_last_step);
            }
            $a->save();
            DB::table('admin_mfa_challenges')->where('id', $c->id)->delete();
            $r->session()->forget('admin.challenge');
            Auth::guard('admin')->login($a, false); // rotates and destroys old ID
            $r->session()->regenerateToken();
            $proof = bin2hex(random_bytes(32));
            DB::table('admin_mfa_grants')->insert(['id' => hash('sha256', $proof), 'admin_id' => $a->id,
                'session_hash' => hash('sha256', $r->session()->getId()), 'auth_version' => $a->auth_version,
                'started_at' => now()->timestamp, 'last_seen_at' => now()->timestamp, 'authenticated_at' => now()->timestamp]);
            $r->session()->put('admin.proof', $proof);
            $this->event('mfa_authenticated');
            return $codes;
        });
    }

    private function grant(Request $r, AdminIdentity $a): object
    {
        $principal = Auth::guard('admin')->user(); $proof = $r->session()->get('admin.proof');
        abort_unless($principal instanceof AdminIdentity && (int) $principal->id === (int) $a->id && is_string($proof), 401);
        $g = DB::table('admin_mfa_grants')->where('id', hash('sha256', $proof))->first();
        $now = now()->timestamp;
        abort_unless($g && $a->mfa_state === 'active' && (int) $g->admin_id === (int) $a->id
            && (int) $g->auth_version === (int) $a->auth_version
            && hash_equals($g->session_hash, hash('sha256', $r->session()->getId()))
            && $now >= (int) $g->started_at && $now < (int) $g->started_at + 28800
            && $now >= (int) $g->last_seen_at && $now < (int) $g->last_seen_at + 900, 401, 'ADMIN_SESSION_INVALID');
        return $g;
    }

    public function requireGrant(Request $r): void
    {
        DB::transaction(function () use ($r) {
            $a = $this->identity(); $g = $this->grant($r, $a);
            DB::table('admin_mfa_grants')->where('id', $g->id)->update(['last_seen_at' => now()->timestamp]);
        });
    }

    public function recover(Request $r, #[\SensitiveParameter] string $code): void
    {
        $this->throttle($r, 'mfa:1');
        DB::transaction(function () use ($r, $code) {
            $a = $this->identity(); $c = $this->challenge($r, $a);
            abort_unless($c->purpose === 'verify' && $a->mfa_state === 'active', 403);
            abort_unless(preg_match('/\A[0-9a-f]{64}\z/D', $code)
                && DB::table('admin_recovery_codes')->where('admin_id', $a->id)->where('digest', hash('sha256', $code))->delete() === 1, 401, 'ADMIN_FACTOR_REFUSED');
            $this->revokeAll($a);
            // Invalidate the entire old code set and factor, not only the used code.
            DB::table('admin_recovery_codes')->where('admin_id', $a->id)->delete();
            $a->forceFill(['mfa_state' => 'recovery_required', 'totp_ciphertext' => null, 'totp_last_step' => -1])->save();
            $this->issueChallenge($r, $a, 'enroll');
            $this->event('recovery_reenrollment_required');
        });
    }

    public function reauthenticate(Request $r, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $otp): void
    {
        $this->throttle($r, 'mfa:1');
        DB::transaction(function () use ($r, $password, $otp) {
            $a = $this->identity(); $g = $this->grant($r, $a);
            abort_unless(Hash::check($password, $a->password), 401, 'ADMIN_AUTH_FAILED');
            $this->advance($a, $a->totp_ciphertext, $otp, (int) $a->totp_last_step);
            DB::table('admin_mfa_grants')->where('id', $g->id)->update(['authenticated_at' => now()->timestamp]);
            $this->event('reauthenticated');
        });
    }

    public function changeFactor(Request $r): void
    {
        DB::transaction(function () use ($r) {
            $a = $this->identity(); $g = $this->grant($r, $a);
            abort_unless(now()->timestamp >= (int) $g->authenticated_at && now()->timestamp < (int) $g->authenticated_at + 300, 403, 'ADMIN_RECENT_AUTH_REQUIRED');
            $this->revokeAll($a);
            DB::table('admin_recovery_codes')->where('admin_id', $a->id)->delete();
            $a->forceFill(['mfa_state' => 'recovery_required', 'totp_ciphertext' => null, 'totp_last_step' => -1])->save();
            Auth::guard('admin')->forgetUser();
            $this->issueChallenge($r, $a, 'enroll');
            $this->event('factor_change_reenrollment_required');
        });
    }

    private function revokeCurrentGrant(Request $r): void
    {
        $proof = $r->session()->get('admin.proof');
        if (is_string($proof)) DB::table('admin_mfa_grants')->where('id', hash('sha256', $proof))->delete();
    }

    public function logout(Request $r): void
    {
        DB::transaction(function () use ($r) {
            $this->revokeCurrentGrant($r);
            $token = $r->session()->get('admin.challenge');
            if (is_string($token)) DB::table('admin_mfa_challenges')->where('id', hash('sha256', $token))->delete();
            Auth::guard('admin')->forgetUser();
            $r->session()->invalidate(); $r->session()->regenerateToken();
            $this->event('logout', null);
        });
    }
}
