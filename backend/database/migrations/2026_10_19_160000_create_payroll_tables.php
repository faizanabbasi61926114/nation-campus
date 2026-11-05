<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->string('month', 7);                              // 2026-10
            $table->string('name', 120);
            $table->string('status', 12)->default('draft');          // draft|calculated|approved|paid
            $table->decimal('total_gross', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('total_net', 12, 2)->default(0);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('paid_on')->nullable();
            $table->timestamps();
            $table->unique(['campus_id', 'month'], 'payroll_run_unique');
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->decimal('basic', 10, 2)->default(0);
            $table->decimal('house_rent', 10, 2)->default(0);
            $table->decimal('medical', 10, 2)->default(0);
            $table->decimal('other_earnings', 10, 2)->default(0);
            $table->decimal('gross', 10, 2)->default(0);
            $table->decimal('eobi', 10, 2)->default(0);              // EOBI: 2% of gross, capped ceiling
            $table->decimal('income_tax', 10, 2)->default(0);        // slabs from settings
            $table->decimal('other_deductions', 10, 2)->default(0);
            $table->decimal('total_deductions', 10, 2)->default(0);
            $table->decimal('net_pay', 10, 2)->default(0);
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0);
            $table->string('status', 10)->default('pending');        // pending|paid
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'staff_id'], 'payslip_unique');
            $table->index(['campus_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};
