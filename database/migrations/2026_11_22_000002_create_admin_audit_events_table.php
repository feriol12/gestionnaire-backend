<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Socle minimal commun BE-03 / BE-04 — journal d'audit administratif.
 *
 * BE-03 n'y écrit que dans la transaction de changement de statut (A3) ; la
 * suite du système d'audit (consultation restreinte, rétention, extension à
 * d'autres actions) appartient à BE-04.
 *
 * - Append-only par convention applicative : aucune mise à jour ni
 *   suppression n'est codée ; pas de colonne updated_at ; aucune purge (Q8).
 * - Aucune FK : ni la ligne admin ni la ligne client ne peuvent emporter le
 *   journal par cascade.
 * - reason : motif en clair (Q3), jamais exposé par une API ni une
 *   notification ; NULL pour les refus journalisés sans motif.
 * - Aucune donnée financière, aucun secret MFA, aucun code de récupération.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('action', 48);
            $table->string('outcome', 32);
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->unsignedBigInteger('auth_version_before')->nullable();
            $table->unsignedBigInteger('auth_version_after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
    }
};
