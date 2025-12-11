<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_dependencies', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('required_fee_id');
            $table->bigInteger('dependent_fee_id');
            $table->string('required_fee_type', 50);
            $table->string('dependent_fee_type', 50);
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            
            $table->index(['required_fee_id', 'required_fee_type']);
            $table->index(['dependent_fee_id', 'dependent_fee_type']);
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_dependencies');
    }
};