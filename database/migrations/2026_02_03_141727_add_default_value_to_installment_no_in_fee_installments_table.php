<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pour MySQL/PostgreSQL
        Schema::table('fee_installments', function (Blueprint $table) {
            $table->integer('installment_no')->default(1)->change();
        });
        
        // Note: Pour modifier une colonne, vous aurez peut-être besoin d'installer
        // le package doctrine/dbal: composer require doctrine/dbal
    }

    public function down(): void
    {
        Schema::table('fee_installments', function (Blueprint $table) {
            $table->integer('installment_no')->default(null)->change();
        });
    }
};