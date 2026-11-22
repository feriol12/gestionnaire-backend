<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Socle minimal commun BE-03 / BE-05 — outbox transactionnelle (DM-007, A3).
 *
 * BE-03 insère UNE intention neutre dans la même transaction que la
 * transition ; aucun envoi n'est fait ici. Le worker de traitement, la
 * sélection/bail, les retries, la déduplication d'envoi et le suivi des
 * erreurs appartiennent à BE-05, qui pourra compléter ce schéma.
 *
 * - event_id : clé d'idempotence unique de l'intention.
 * - correlation_id : event_id de l'audit administratif de la transition.
 * - Aucune adresse email copiée (résolue par le worker à l'envoi), aucun
 *   motif, aucune donnée financière : payload limité à un gabarit neutre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('type', 64);
            $table->unsignedBigInteger('recipient_user_id')->index();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
