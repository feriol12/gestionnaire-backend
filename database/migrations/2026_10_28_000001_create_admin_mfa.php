<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('admin_identities', function (Blueprint $t) {
            $t->string('mfa_state', 32)->default('pending_enrollment');
            $t->text('totp_ciphertext')->nullable();
            $t->bigInteger('totp_last_step')->default(-1);
        });
        Schema::create('admin_mfa_challenges', function (Blueprint $t) {
            $t->string('id', 64)->primary(); // hash of random token held only in session
            $t->foreignId('admin_id')->constrained('admin_identities')->cascadeOnDelete();
            $t->string('session_hash', 64);
            $t->bigInteger('auth_version');
            $t->string('purpose', 16);
            $t->text('pending_secret')->nullable();
            $t->bigInteger('expires_at')->index();
        });
        Schema::create('admin_mfa_grants', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->foreignId('admin_id')->constrained('admin_identities')->cascadeOnDelete();
            $t->string('session_hash', 64);
            $t->bigInteger('auth_version');
            $t->bigInteger('started_at');
            $t->bigInteger('last_seen_at');
            $t->bigInteger('authenticated_at');
        });
        Schema::create('admin_recovery_codes', function (Blueprint $t) {
            $t->string('digest', 64)->primary();
            $t->foreignId('admin_id')->constrained('admin_identities')->cascadeOnDelete();
        });
        Schema::create('admin_auth_attempts', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->unsignedInteger('attempts');
            $t->bigInteger('expires_at')->index();
        });
        Schema::create('admin_security_events', function (Blueprint $t) {
            $t->id();
            $t->string('event', 48);
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['admin_security_events', 'admin_auth_attempts', 'admin_recovery_codes', 'admin_mfa_grants', 'admin_mfa_challenges'] as $table) Schema::dropIfExists($table);
        Schema::table('admin_identities', fn (Blueprint $t) => $t->dropColumn(['mfa_state', 'totp_ciphertext', 'totp_last_step']));
    }
};
