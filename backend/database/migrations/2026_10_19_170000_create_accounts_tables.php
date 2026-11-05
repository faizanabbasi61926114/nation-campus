<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete(); // null = head-office / shared
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 12);                             // asset|liability|equity|income|expense
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_system')->default(false);           // seeded chart, not deletable
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'account_code_unique');
        });

        Schema::create('journal_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->string('voucher_no', 40);
            $table->date('voucher_date');
            $table->string('type', 16)->default('journal');         // receipt|payment|journal|contra
            $table->string('narrative', 255);
            $table->string('source', 20)->default('manual');        // manual|fee_payment|payroll|expense
            $table->string('source_ref', 60)->nullable();           // receipt no / payroll run id
            $table->decimal('amount', 12, 2)->default(0);           // total debits (= total credits)
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('posted');        // posted|reversed
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique('voucher_no');
            $table->index(['campus_id', 'voucher_date']);
            $table->index(['source', 'source_ref']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('journal_vouchers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->string('memo', 160)->nullable();
            $table->timestamps();
            $table->index(['account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_vouchers');
        Schema::dropIfExists('accounts');
    }
};
