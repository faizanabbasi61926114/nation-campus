<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete(); // null = organisation-wide
            $table->string('name', 120);
            $table->string('code', 20);
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name', 120);
            $table->string('category', 20)->default('teaching'); // teaching|administrative|support|senior
            $table->string('grade', 20)->nullable();             // BPS-style grade / pay scale
            $table->decimal('min_salary', 10, 2)->nullable();
            $table->decimal('max_salary', 10, 2)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_no', 30);
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->text('cnic')->nullable();                       // encrypted at rest (APP_KEY)
            $table->string('gender', 10)->default('male');
            $table->string('email', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('joined_on');
            $table->date('confirmed_on')->nullable();
            $table->date('resigned_on')->nullable();
            $table->decimal('base_salary', 10, 2)->default(0);
            $table->decimal('house_rent_percent', 5, 2)->default(30); // Pakistan convention: HRA 30% of basic
            $table->decimal('medical_allowance', 10, 2)->default(0);
            $table->decimal('other_deductions', 10, 2)->default(0);
            $table->string('bank_account', 40)->nullable();
            $table->string('status', 12)->default('active');      // active|probation|resigned|terminated
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('employee_no');
            $table->index(['campus_id', 'status']);
        });

        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('type', 16)->default('annual');        // annual|casual|sick|earned|unpaid|media
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 2)->default(0);            // calculated on save
            $table->string('reason', 255);
            $table->string('status', 10)->default('pending');     // pending|approved|rejected|cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
            $table->index(['staff_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }
};
