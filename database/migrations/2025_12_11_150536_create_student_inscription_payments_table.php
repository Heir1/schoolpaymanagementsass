<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_inscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('inscription_fee_id')->constrained('inscription_fees');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->decimal('amount_paid', 12, 2);
            $table->text('transaction_reference')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            
            $table->index('student_id');
            $table->index('inscription_fee_id');
            $table->index('payment_method_id');
            $table->index('status');
            $table->index('payment_date');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_inscription_payments');
    }
};