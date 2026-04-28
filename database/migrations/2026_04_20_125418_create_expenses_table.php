<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('description', 255);
            $table->decimal('amount', 10, 2);
            $table->enum('category', ['Nourriture', 'Transport', 'Factures', 'Loisirs', 'Imprévu']);
            $table->date('date');
            $table->timestamps();


            // Index pour optimiser les requêtes
            $table->index('user_id');
            $table->index('date');
            $table->index('category');
            $table->index(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
