<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('students')
                ->onDelete('cascade');
            $table->foreignId('document_id')
                ->constrained('inscription_documents')
                ->onDelete('cascade');
            $table->text('file_url');
            $table->boolean('is_correct')->nullable();
            $table->text('comment')->nullable();
            
            // Timestamps standards
            $table->timestamps();
            $table->softDeletes();
            
            // Champs d'audit
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            
            // Index
            $table->index(['student_id', 'document_id']);
            $table->index('is_correct');
            $table->index('created_by');
            $table->index('updated_by');
            
            // Clés étrangères vers users
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};