<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('type', 30);              // monthly | mid_term | annual | unit_test | board_preview
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 12)->default('draft'); // draft|scheduled|ongoing|completed|published|archived
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });

        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->unsignedSmallInteger('max_marks')->default(100);
            $table->unsignedSmallInteger('pass_marks')->default(33);
            $table->unsignedSmallInteger('weightage')->default(100); // theory/practical split not modelled; reserved
            $table->timestamps();
            $table->unique(['exam_id', 'subject_id']);
        });

        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable(); // null = org default
            $table->decimal('min_percent', 5, 2);
            $table->string('grade', 8);
            $table->string('remark', 40)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'campus_id']);
        });

        Schema::create('exam_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('exam_subject_id')->constrained('exam_subjects')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('marks_obtained', 6, 2)->nullable(); // null = absent
            $table->boolean('is_absent')->default(false);
            $table->text('remarks')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exam_id', 'student_id', 'exam_subject_id'], 'exam_marks_unique');
            $table->index(['exam_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_marks');
        Schema::dropIfExists('grading_scales');
        Schema::dropIfExists('exam_subjects');
        Schema::dropIfExists('exams');
    }
};
