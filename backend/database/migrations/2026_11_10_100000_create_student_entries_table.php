<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The student gate log: every time a child is tapped In or Out on campus.
 *
 * Two sources write here. The front desk records the tap; a roll call that marks a
 * pupil present also drops an arrival, so a family hears from the school whether or
 * not anyone was standing at the gate. One row per tap — a child who goes out for a
 * medical appointment and back writes two rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('direction', 4);                        // in|out
            $table->dateTime('at');
            $table->string('gate', 40)->nullable();                // Main gate, Back gate, Bus 12…
            $table->string('source', 12)->default('front_desk');   // front_desk|roll_call
            $table->string('remarks', 190)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // The first arrival and the first departure of a day each raise one in-app alert
            // for the family; this stamps the row that did it so a re-tap stays quiet.
            $table->timestamp('parent_notified_at')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'at']);
            $table->index(['student_id', 'direction', 'at']);
            $table->index(['organization_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_entries');
    }
};
