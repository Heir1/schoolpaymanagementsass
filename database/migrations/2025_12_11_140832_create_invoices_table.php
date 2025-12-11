<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('class_fee_id')->nullable()->constrained('fees');
            $table->foreignId('student_fee_id')->nullable()->constrained('student_fees');
            $table->foreignId('group_fee_id')->nullable()->constrained('group_fees');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            $table->string('status', 20)->default('unpaid');
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            
            $table->index('student_id');
            $table->index('school_id');
            $table->index(['student_id', 'status']);
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};