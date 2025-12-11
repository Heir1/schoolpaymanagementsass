<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->decimal('amount_paid', 12, 2);
            $table->text('transaction_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            
            $table->index('invoice_id');
            $table->index('payment_method_id');
            $table->index('paid_at');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};