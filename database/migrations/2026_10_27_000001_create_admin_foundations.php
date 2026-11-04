<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $raw = (string) DB::selectOne('select version() as version')->version;
            $maria = str_contains(strtolower($raw), 'mariadb');
            preg_match($maria ? '/(\d+\.\d+\.\d+)(?=[^\d]*-MariaDB)/i' : '/\d+\.\d+\.\d+/', $raw, $matches);
            $version = $maria ? ($matches[1] ?? '') : ($matches[0] ?? '');
            if ($version === '' || version_compare($version, $maria ? '10.2.1' : '8.0.16', '<')) {
                throw new RuntimeException('Admin singleton requires enforced CHECK constraints: MySQL >= 8.0.16 or MariaDB >= 10.2.1.');
            }
        } elseif (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported database for the administrative singleton.');
        }

        if ($driver === 'sqlite') {
            DB::statement('CREATE TABLE admin_identities (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1), email VARCHAR(255) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, remember_token VARCHAR(100) NULL, auth_version INTEGER NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL)');
        } else {
            // Keep the constraint in CREATE itself: no unprotected table if a later DDL fails.
            $idType = in_array($driver, ['mysql', 'mariadb'], true) ? 'BIGINT UNSIGNED' : 'BIGINT';
            DB::statement("CREATE TABLE admin_identities (id {$idType} NOT NULL PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, remember_token VARCHAR(100) NULL, auth_version BIGINT NOT NULL DEFAULT 1, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, CONSTRAINT admin_identity_singleton CHECK (id = 1))");
        }
        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Standard DatabaseSessionHandler column, scoped to this separate table.
            $table->foreignId('user_id')->nullable()->index()->constrained('admin_identities')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
        Schema::dropIfExists('admin_identities');
    }
};
