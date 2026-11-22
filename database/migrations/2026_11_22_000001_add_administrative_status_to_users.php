<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * BE-03 (Q2) — statut administratif du compte client et version d'accès.
 *
 * - administrative_status : 'active' | 'suspended' (CHECK sur MySQL/MariaDB/
 *   PostgreSQL ; les comptes existants deviennent 'active' par défaut).
 * - auth_version : entier monotone ; chaque transition l'incrémente et toute
 *   session dont la version diffère est rejetée (aucune résurrection).
 * - suspended_at / suspended_by : métadonnées de la suspension COURANTE
 *   (remises à NULL à la réactivation) ; l'historique durable est dans
 *   admin_audit_events. suspended_by référence le singleton admin sans FK :
 *   aucune cascade ne doit jamais toucher la ligne users.
 *
 * Aucune donnée existante n'est modifiée ni supprimée.
 */
return new class extends Migration
{
    private const CHECK = 'users_administrative_status_check';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('administrative_status', 16)->default('active');
            $table->unsignedBigInteger('auth_version')->default(1);
            $table->timestamp('suspended_at')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE users ADD CONSTRAINT '.self::CHECK
                ." CHECK (administrative_status IN ('active', 'suspended'))");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE users DROP CONSTRAINT '.self::CHECK);
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CHECK);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['administrative_status', 'auth_version', 'suspended_at', 'suspended_by']);
        });
    }
};
