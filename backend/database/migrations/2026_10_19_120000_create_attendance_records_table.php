<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->string('subject_type', 16);          // student | staff
            $table->unsignedBigInteger('subject_id');
            $table->date('date');
            $table->unsignedTinyInteger('period_no')->default(0); // 0 = full day
            $table->string('status', 12);                // present|absent|late|leave|excused
            $table->text('remarks')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'date', 'period_no'], 'attendance_unique');
            $table->index(['campus_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
