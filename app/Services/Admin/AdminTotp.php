<?php

namespace App\Services\Admin;

use PragmaRX\Google2FA\Google2FA;

final class AdminTotp
{
    public function secret(): string
    {
        return (new Google2FA)->generateSecretKey(32);
    }

    public function acceptedStep(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, int $last): ?int
    {
        if (!preg_match('/\A[0-9]{6}\z/D', $code)) return null;
        // Library timestamps are COUNTERS, not Unix seconds. Explicitly bound ±1.
        $step = (new Google2FA)->verifyKeyNewer($secret, $code, $last, 1, intdiv(now()->timestamp, 30));
        return is_int($step) && $step > $last ? $step : null;
    }
}
