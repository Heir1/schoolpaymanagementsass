<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->text('rule_name');
            // REMPLACER CETTE LIGNE :
            // $table->text('rule_type');
            // PAR CELLE-CI :
            $table->string('rule_type', 100); // 100 caractères est généralement suffisant
            $table->foreignId('fee_type_id')->constrained('fee_types');
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            
            $table->index('school_id');
            $table->index('fee_type_id');
            $table->index(['rule_type', 'fee_type_id']); // Cet index fonctionnera maintenant
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_rules');
    }
};