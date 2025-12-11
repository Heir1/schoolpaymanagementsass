<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_fee_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_fee_id')->constrained('group_fees');
            $table->integer('installment_no');
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            
            $table->index('group_fee_id');
            $table->index('installment_no');
            $table->index('due_date');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_fee_installments');
    }
};