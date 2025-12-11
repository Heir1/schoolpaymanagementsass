<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_required_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')
                ->constrained('classes')
                ->onDelete('cascade');
            $table->foreignId('document_id')
                ->constrained('inscription_documents')
                ->onDelete('cascade');
            $table->boolean('is_mandatory')->default(true);
            
            // Timestamps standards
            $table->timestamps();
            $table->softDeletes();
            
            // Champs d'audit
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            
            // Index
            $table->unique(['class_id', 'document_id']);
            $table->index('created_by');
            $table->index('updated_by');
            
            // Clés étrangères vers users
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_required_documents');
    }
};