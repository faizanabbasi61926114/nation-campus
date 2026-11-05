<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->string('id', 128)->primary();    // e.g. students.manage
            $table->string('group', 64);             // e.g. students
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 64);
            $table->boolean('is_system')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->string('permission_id', 128);
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete(); // null = all campuses of org
            $table->unique(['role_id', 'user_id', 'campus_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->string('phone', 24)->nullable()->after('email');
            $table->string('cnic', 20)->nullable()->after('phone'); // National ID, 12345-1234567-1
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->index(['organization_id', 'campus_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('campus_id');
            $table->dropColumn(['phone', 'cnic', 'is_active', 'last_login_at']);
        });
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
