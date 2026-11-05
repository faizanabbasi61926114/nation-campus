<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campus-life modules: library, transport, hostel, front desk, certificates,
 * calendar and discipline. Status columns stay as strings with the vocabulary
 * enforced in the controllers, matching every other module in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional, so a partially applied run must be re-runnable.
        if (!Schema::hasColumn('campuses', 'school_type')) {
            Schema::table('campuses', function (Blueprint $table) {
                $table->string('school_type', 24)->default('secondary')->after('code'); // montessori|junior|secondary|higher_secondary|college|university
            });
        }

        Schema::create('library_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable(); // null = shared across the organization
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn', 32)->nullable();
            $table->string('category', 48)->nullable();  // Textbook|Reference|Story|Magazine
            $table->string('language', 24)->default('English');
            $table->string('shelf', 24)->nullable();
            $table->unsignedSmallInteger('total_copies')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['campus_id', 'category']);
        });

        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable();
            $table->foreignId('library_book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('copy_no')->default(1);
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('returned_on')->nullable();
            $table->decimal('fine_amount', 10, 2)->default(0);
            $table->boolean('fine_paid')->default(false);
            $table->string('status', 16)->default('borrowed'); // borrowed|returned|lost
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_on']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->string('code', 16);
            $table->string('plate_no', 24)->nullable();
            $table->string('type', 16)->default('van');      // van|coaster|bus
            $table->unsignedSmallInteger('capacity')->default(20);
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 24)->nullable();
            $table->string('driver_cnic', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['campus_id', 'code']);
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->string('name');
            $table->string('code', 16);
            $table->foreignId('transport_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->time('pickup_time')->nullable();
            $table->time('drop_time')->nullable();
            $table->decimal('monthly_fee', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['campus_id', 'code']);
        });

        Schema::create('transport_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_route_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('landmark')->nullable();
            $table->unsignedTinyInteger('order_no')->default(1);
            $table->time('pickup_time')->nullable();
            $table->time('drop_time')->nullable();
            $table->timestamps();
            $table->unique(['transport_route_id', 'order_no']);
        });

        Schema::create('student_transports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_stop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12)->default('both');       // pickup|drop|both
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('status', 16)->default('active');   // active|discontinued
            $table->timestamps();
            $table->unique(['student_id', 'transport_route_id']);
            $table->index(['transport_route_id', 'status']);
        });

        Schema::create('hostel_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->string('name');
            $table->string('gender', 8)->default('boys');      // boys|girls
            $table->foreignId('warden_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['campus_id', 'name']);
        });

        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('hostel_block_id')->constrained()->cascadeOnDelete();
            $table->string('number', 16);
            $table->string('floor', 8)->nullable();
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->decimal('monthly_fee', 10, 2)->default(0);
            $table->string('status', 16)->default('available'); // available|maintenance
            $table->timestamps();
            $table->unique(['hostel_block_id', 'number']);
        });

        Schema::create('hostel_allotments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hostel_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('bed_no')->default(1);
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->string('status', 16)->default('active');    // active|vacated
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['hostel_room_id', 'bed_no']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('visitor_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->string('pass_no', 16)->nullable();
            $table->string('name');
            $table->string('phone', 24)->nullable();
            $table->string('cnic', 20)->nullable();
            $table->string('from_organization')->nullable();
            $table->string('purpose');
            $table->foreignId('meets_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('meets_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->dateTime('in_at');
            $table->dateTime('out_at')->nullable();
            $table->string('gate', 32)->nullable();
            $table->string('photo_id_taken', 8)->default('yes'); // yes|no
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['campus_id', 'in_at']);
            $table->index(['campus_id', 'out_at']);
        });

        Schema::create('certificate_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);                         // bonafide|transfer|character|marksheet|internship|other
            $table->string('title')->nullable();               // addressed-to line, e.g. "To the Embassy of Saudi Arabia"
            $table->text('purpose')->nullable();
            $table->decimal('fee', 10, 2)->default(0);
            $table->string('status', 16)->default('pending');   // pending|approved|rejected|issued
            $table->string('reference_no', 32)->nullable();
            $table->text('body')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reference_no']);
            $table->index(['campus_id', 'status']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id')->nullable();         // null = every campus
            $table->string('title');
            $table->string('type', 16)->default('event');       // holiday|closure|exam|ptm|event|meeting|vacation
            $table->date('start_date');
            $table->date('end_date');
            $table->text('description')->nullable();
            $table->string('audience', 16)->default('all');     // all|students|faculty|staff
            $table->boolean('affects_attendance')->default(false);
            $table->timestamps();
            $table->index(['campus_id', 'start_date']);
        });

        Schema::create('discipline_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('campus_id');
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('incident_on');
            $table->string('category', 24)->default('conduct'); // uniform|punctuality|bullying|conduct|damage|attendance|other
            $table->string('severity', 12)->default('minor');   // minor|moderate|major
            $table->text('description');
            $table->string('action', 24)->default('none');      // none|counselled|verbal_warning|parent_called|detention|suspension
            $table->unsignedTinyInteger('suspension_days')->default(0);
            $table->boolean('parent_notified')->default(false);
            $table->string('status', 16)->default('open');      // open|closed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'status']);
            $table->index(['student_id', 'incident_on']);
        });
    }

    public function down(): void
    {
        foreach ([
            'discipline_cases', 'calendar_events', 'certificate_requests', 'visitor_logs',
            'hostel_allotments', 'hostel_rooms', 'hostel_blocks',
            'student_transports', 'transport_stops', 'transport_routes', 'transport_vehicles',
            'library_loans', 'library_books',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('campuses', 'school_type')) {
            Schema::table('campuses', fn (Blueprint $table) => $table->dropColumn('school_type'));
        }
    }
};
