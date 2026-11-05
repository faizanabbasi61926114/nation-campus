<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->cascadeOnDelete(); // null = organisation-wide
            $table->string('title', 160);
            $table->text('body');
            $table->string('audience', 16)->default('all');        // all|staff|students|guardians
            $table->string('priority', 8)->default('normal');      // normal|high|urgent
            $table->string('status', 10)->default('draft');        // draft|published|archived
            $table->dateTime('publish_at')->nullable();            // schedule; null = immediately on publish
            $table->date('expires_at')->nullable();
            $table->boolean('send_email')->default(false);
            $table->boolean('send_sms')->default(false);
            $table->unsignedInteger('recipient_count')->default(0); // user seats resolved at publish time
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status', 'publish_at']);
            $table->index(['organization_id', 'audience']);
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['announcement_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->unsignedBigInteger('campus_id')->nullable();     // reporting hint only; threads are org-wide
            $table->string('subject', 180);
            $table->text('body');
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('messages')->cascadeOnDelete();
            $table->string('channel', 12)->default('in_app');        // in_app|email (SMS is one-way broadcast)
            $table->boolean('send_email')->default(false);
            $table->boolean('send_sms')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'read_at']);
            $table->index(['sender_id', 'created_at']);
            $table->index(['organization_id', 'parent_id']);
        });

        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('channel', 8);                            // email|sms|in_app
            $table->string('recipient', 160);                        // email address or MSISDN
            $table->string('subject', 180)->nullable();
            $table->string('status', 8);                             // sent|skipped|failed
            $table->string('provider', 40)->nullable();              // mailer name or SMS driver
            $table->string('error', 255)->nullable();
            $table->foreignId('announcement_id')->nullable()->constrained('announcements')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'channel', 'status']);
            $table->index(['announcement_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
