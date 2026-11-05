<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->string('application_no', 32)->unique();
            $table->string('applicant_name');
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('dob');
            $table->string('cnic_bform', 20)->nullable();
            $table->string('father_name');
            $table->string('mother_name')->nullable();
            $table->string('phone', 24);
            $table->string('previous_school')->nullable();
            $table->unsignedSmallInteger('previous_marks')->nullable(); // % or GMAT-style
            $table->foreignId('program_id')->constrained();
            $table->foreignId('class_id')->nullable()->constrained();
            $table->string('status', 24)->default('pending'); // pending|screening|interview|test|offered|accepted|enrolled|rejected|waitlisted|withdrawn
            $table->text('remarks')->nullable();
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->string('admission_no', 32)->unique();
            $table->string('roll_no', 32)->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('dob');
            $table->string('cnic_bform', 20)->nullable();
            $table->string('father_name');
            $table->string('mother_name')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 24)->nullable();
            $table->foreignId('guardian_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('city', 64)->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('class_id')->nullable()->constrained();
            $table->foreignId('section_id')->nullable()->constrained();
            $table->foreignId('academic_year_id')->nullable()->constrained();
            $table->foreignId('program_id')->nullable()->constrained();
            $table->foreignId('admission_id')->nullable()->constrained('admissions')->nullOnDelete();
            $table->string('status', 24)->default('active'); // active|inactive|graduated|transferred|suspended
            $table->date('admission_date')->nullable();
            $table->date('graduation_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
            $table->index(['class_id', 'section_id']);
        });

        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);   // bform|cnic|previous_result|medical|photo
            $table->string('name');
            $table->string('path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
        Schema::dropIfExists('students');
        Schema::dropIfExists('admissions');
    }
};
