<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('name');                       // Tuition Fee — Grade 9 (2026-27)
            $table->string('category', 30)->default('tuition'); // tuition|admission|exam|transport|lab|library|sports|other
            $table->decimal('amount', 10, 2);
            $table->decimal('gst_percent', 4, 2)->default(0);
            $table->enum('frequency', ['one_time', 'monthly', 'quarterly', 'annual'])->default('monthly');
            $table->unsignedTinyInteger('due_day')->default(10); // day of month the instalment is due
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['campus_id', 'is_active']);
        });

        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->nullable()->constrained('fee_structures')->nullOnDelete();
            $table->string('title');
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('amount', 10, 2);              // gross before discount/GST
            $table->decimal('gst_amount', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('late_fee', 10, 2)->default(0);
            $table->string('status', 12)->default('unpaid'); // unpaid|partial|paid|waived|cancelled
            $table->string('session_month', 7)->nullable();  // 2026-10 for recurring instalments
            $table->timestamps();
            $table->unique(['student_id', 'fee_structure_id', 'session_month'], 'invoice_unique');
            $table->index(['campus_id', 'status']);
            $table->index(['student_id', 'due_date']);
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('invoice_id')->constrained('fee_invoices')->cascadeOnDelete();
            $table->string('receipt_no', 40);
            $table->decimal('amount', 10, 2);
            $table->string('channel', 20);                  // cash|bank_transfer|jazzcash|easypaisa|cheque
            $table->string('reference', 60)->nullable();    // transaction/cheque number
            $table->date('paid_on');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('cleared'); // cleared|pending|reversed
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique('receipt_no');
            $table->index(['campus_id', 'paid_on']);
        });

        Schema::create('fee_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('fee_invoices')->nullOnDelete();
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('reason', 255);
            $table->string('status', 10)->default('pending'); // pending|approved|rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_waivers');
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_invoices');
        Schema::dropIfExists('fee_structures');
    }
};
