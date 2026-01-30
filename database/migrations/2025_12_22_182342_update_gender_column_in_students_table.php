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
        Schema::table('students', function (Blueprint $table) {
            // Si vous voulez utiliser VARCHAR
            $table->string('gender', 10)->change();
            
            // OU si vous préférez ENUM
            // $table->enum('gender', ['male', 'female', 'other'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Revenir à la définition précédente
            $table->string('gender', 1)->change();
            
            // OU pour ENUM
            // $table->string('gender', 1)->change();
        });
    }
};