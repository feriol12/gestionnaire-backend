<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminMfa;
use Illuminate\Console\Command;

final class ProvisionAdmin extends Command
{
    protected $signature = 'admin:provision';
    protected $description = 'Interactive singleton provisioning, pending mandatory MFA enrollment';

    public function handle(AdminMfa $mfa): int
    {
        // No stdin redirection, CLI password argument, or silent visible-input fallback.
        if (!$this->input->isInteractive() || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
            $this->error('An interactive terminal with hidden input is required.'); return self::FAILURE;
        }
        $email = $this->ask('Administrative email');
        $password = $this->secret('Password (16–128 characters)', false);
        $confirmation = $this->secret('Confirm password', false);
        if (!is_string($email) || !is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) return self::FAILURE;
        try { $mfa->provision($email, $password); }
        catch (\Illuminate\Database\QueryException) { $this->error('Provisioning refused; check singleton and schema locally.'); return self::FAILURE; }
        finally { unset($password, $confirmation); }
        $this->info('Identity created pending enrollment; no administrative privilege granted.');
        return self::SUCCESS;
    }
}
