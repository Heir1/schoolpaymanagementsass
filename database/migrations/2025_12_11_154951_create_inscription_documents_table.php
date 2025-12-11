<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscription_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            
            // Timestamps standards
            $table->timestamps();
            $table->softDeletes();
            
            // Champs d'audit
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            
            // Index pour les champs d'audit
            $table->index('created_by');
            $table->index('updated_by');
            
            // Clés étrangères vers users (si vous voulez les contraintes)
            // Note: Comme users a un UUID, on ne peut pas utiliser foreignId()
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscription_documents');
    }
};