<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 36)->primary(); // CORRIGÉ: char(36) au lieu de uuid()
            $table->string('full_name', 255)->nullable();
            $table->string('phone_or_email', 255)->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('password', 255);
            $table->string('confirm_password', 255);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('phone_or_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};