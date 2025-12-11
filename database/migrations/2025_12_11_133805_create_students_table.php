<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('class_id')->constrained('classes');
            $table->string('student_code')->unique();
            $table->text('first_name');
            $table->text('last_name');
            $table->text('middle_name');
            $table->string('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->foreignId('province_id')->nullable()->constrained('provinces');
            $table->foreignId('city_id')->nullable()->constrained('cities');
            $table->text('street');
            $table->foreignId('student_group_id')->nullable()->constrained('student_groups');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            
            $table->index(['school_id', 'class_id']);
            $table->index('student_code');
            $table->index('student_group_id');
            $table->index('is_approved');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};