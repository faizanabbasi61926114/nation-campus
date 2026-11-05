<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable();
            $table->string('name');            // 2026-2027
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable(); // null = offered org-wide
            $table->string('name');                      // e.g. Matric (Science)
            $table->string('code', 16);
            $table->string('level', 32);                 // primary|middle|matric|intermediate|undergrad
            $table->string('board', 32)->nullable();     // FBISE|BISE-LHR|Cambridge
            $table->unsignedTinyInteger('duration_years')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained();
            $table->string('name');              // Grade 9 / First Year
            $table->unsignedTinyInteger('level_no')->default(1);
            $table->timestamps();
            $table->unique(['campus_id', 'program_id', 'name']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('class_id')->constrained()->cascadeOnDelete();
            $table->string('name', 16);          // A, B
            $table->unsignedSmallInteger('capacity')->default(40);
            $table->foreignId('class_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['class_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable();
            $table->string('name');
            $table->string('code', 16);
            $table->string('group', 32)->nullable();  // Science|Arts|Compulsory
            $table->unsignedTinyInteger('credit_hours')->default(100); // max marks basis
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_compulsory')->default(false);
            $table->unique(['class_id', 'subject_id']);
        });

        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 1=Mon .. 7=Sun
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room', 32)->nullable();
            $table->index(['section_id', 'day_of_week']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['timetable_slots', 'class_subjects', 'subjects', 'sections', 'classes', 'programs', 'academic_years'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
