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
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            
            // MODIFIEZ CE BLOC : Remplacez morphs par des colonnes personnalisées
            // $table->morphs('tokenable'); // ← À SUPPRIMER
            $table->string('tokenable_type'); // ← À AJOUTER
            $table->uuid('tokenable_id');    // ← À AJOUTER (UUID au lieu de bigInteger)
            
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            
            // Ajoutez l'index manuellement
            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};