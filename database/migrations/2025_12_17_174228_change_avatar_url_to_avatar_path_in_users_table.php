<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Renommer la colonne
            $table->renameColumn('avatar_url', 'avatar_path');
            
            // Changer le type si nécessaire (text reste approprié)
            $table->text('avatar_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('avatar_path', 'avatar_url');
        });
    }
};