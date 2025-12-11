<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            
            // CORRIGÉ : Utiliser char(36) au lieu de uuid()
            $table->char('user_id', 36);
            
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('role_id')->constrained('roles');
            $table->timestamps();
            
            // CORRIGÉ : Aussi pour created_by et updated_by
            $table->char('created_by', 36)->nullable();
            $table->char('updated_by', 36)->nullable();
            
            $table->softDeletes();
            
            // Clé étrangère corrigée
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
                  
            $table->index(['user_id', 'school_id']);
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};