<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportFrontDeskTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;    // super admin (has *), head office — scope via X-Campus-Id
    private string $accounts; // accounts_officer — transport.view, no manage, no frontdesk
    private int $lhr;
    private int $isb;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        $login = function (string $email): string {
            $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123!'])->json('token');
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            return $token;
        };
        $this->admin = $login('admin@nationcampus.edu.pk');
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;
    }

    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    private function makeStudent(int $campusId, string $no, string $status = 'active'): Student
    {
        return Student::create([
            'organization_id' => 1, 'campus_id' => $campusId, 'admission_no' => $no,
            'first_name' => 'St', 'last_name' => $no, 'gender' => 'male', 'dob' => '2012-01-01',
            'father_name' => 'F', 'status' => $status,
        ]);
    }

    private function makeVehicle(int $campusId, string $code, int $capacity): TransportVehicle
    {
        return TransportVehicle::create([
            'organization_id' => 1, 'campus_id' => $campusId, 'code' => $code,
            'type' => 'van', 'capacity' => $capacity, 'is_active' => true,
        ]);
    }

    // ---- Transport ------------------------------------------------------

    public function test_route_and_vehicle_require_a_campus_in_head_office_context(): void
    {
        // Admin has no campus selected — head-office context must pick one first.
        $this->as($this->admin)->postJson('/api/v1/transport/routes', [
            'name' => 'No Campus', 'code' => 'R-99',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Select a campus before adding a route.']);

        $this->as($this->admin)->postJson('/api/v1/transport/vehicles', [
            'code' => 'V-99', 'type' => 'van', 'capacity' => 20,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Select a campus before adding a vehicle.']);
    }

    public function test_capacity_overload_is_rejected_naming_vehicle_capacity(): void
    {
        $this->as($this->admin, $this->lhr);
        $vehicle = $this->makeVehicle($this->lhr, 'Van-01', 2);
        $route = TransportRoute::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'Johar Loop',
            'code' => 'R-03', 'transport_vehicle_id' => $vehicle->id, 'is_active' => true,
        ]);

        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $this->makeStudent($this->lhr, 'TR-A')->id,
            'transport_route_id' => $route->id, 'kind' => 'both',
        ])->assertCreated();

        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $this->makeStudent($this->lhr, 'TR-B')->id,
            'transport_route_id' => $route->id, 'kind' => 'pickup',
        ])->assertCreated();

        // Third student would exceed the van's two seats.
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $this->makeStudent($this->lhr, 'TR-C')->id,
            'transport_route_id' => $route->id, 'kind' => 'both',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Route R-03 carries 3 students but Van-01 seats 2.']);

        $this->assertSame(2, StudentTransport::where('transport_route_id', $route->id)->count());
    }

    public function test_student_cannot_hold_two_active_routes_and_must_be_active(): void
    {
        $vehicle = $this->makeVehicle($this->lhr, 'Van-10', 20);
        $r1 = TransportRoute::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'North', 'code' => 'R-10', 'transport_vehicle_id' => $vehicle->id]);
        $r2 = TransportRoute::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'South', 'code' => 'R-11', 'transport_vehicle_id' => $vehicle->id]);
        $student = $this->makeStudent($this->lhr, 'TR-D');

        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $student->id, 'transport_route_id' => $r1->id, 'kind' => 'both',
        ])->assertCreated();

        // A different active route is refused, naming the existing one.
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $student->id, 'transport_route_id' => $r2->id, 'kind' => 'both',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'This student is already on route North (R-10). Discontinue that assignment first.']);

        // A non-active student cannot be assigned at all.
        $suspended = $this->makeStudent($this->lhr, 'TR-E', 'suspended');
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $suspended->id, 'transport_route_id' => $r2->id, 'kind' => 'both',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Only active students can be assigned to transport.']);
    }

    public function test_stop_delete_is_blocked_while_students_use_it(): void
    {
        $vehicle = $this->makeVehicle($this->lhr, 'Van-20', 30);
        $route = TransportRoute::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'East', 'code' => 'R-20', 'transport_vehicle_id' => $vehicle->id]);
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/transport/routes/{$route->id}/stops", [
            'name' => 'Hall Road', 'order_no' => 1,
        ])->assertCreated();

        $stop = TransportStop::where('transport_route_id', $route->id)->first();

        // A free stop can be removed.
        $this->as($this->admin, $this->lhr)->deleteJson("/api/v1/transport/stops/{$stop->id}")->assertOk();

        // Once a student is tied to a stop the delete is refused.
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/transport/routes/{$route->id}/stops", [
            'name' => 'Canal Bank', 'order_no' => 2,
        ])->assertCreated();
        $stop2 = TransportStop::where('transport_route_id', $route->id)->where('order_no', 2)->first();

        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $this->makeStudent($this->lhr, 'TR-F')->id,
            'transport_route_id' => $route->id, 'transport_stop_id' => $stop2->id, 'kind' => 'both',
        ])->assertCreated();

        $this->as($this->admin, $this->lhr)->deleteJson("/api/v1/transport/stops/{$stop2->id}")
            ->assertStatus(422)->assertJsonFragment(['message' => '1 students are assigned to stop "Canal Bank". Reassign or discontinue them before removing it.']);
    }

    public function test_duplicate_stop_order_is_rejected(): void
    {
        $route = TransportRoute::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'West', 'code' => 'R-30']);
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/transport/routes/{$route->id}/stops", ['name' => 'S1', 'order_no' => 1])->assertCreated();
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/transport/routes/{$route->id}/stops", ['name' => 'S2', 'order_no' => 1])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Stop number 1 already exists on route R-30.']);
    }

    public function test_route_delete_is_blocked_while_students_are_active(): void
    {
        $vehicle = $this->makeVehicle($this->lhr, 'Van-40', 20);
        $route = TransportRoute::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'Load', 'code' => 'R-40', 'transport_vehicle_id' => $vehicle->id]);
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $this->makeStudent($this->lhr, 'TR-G')->id, 'transport_route_id' => $route->id, 'kind' => 'both',
        ])->assertCreated();

        $this->as($this->admin, $this->lhr)->deleteJson("/api/v1/transport/routes/{$route->id}")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Route R-40 still has 1 active students. Discontinue their assignments before deleting it.']);

        $assignment = StudentTransport::where('transport_route_id', $route->id)->first();
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/transport/assignments/{$assignment->id}/discontinue")
            ->assertOk()->assertJsonPath('data.status', 'discontinued')->assertJsonPath('data.to_date', '2026-10-21');

        $this->as($this->admin, $this->lhr)->deleteJson("/api/v1/transport/routes/{$route->id}")->assertOk();
        $this->assertDatabaseMissing('transport_routes', ['id' => $route->id]);
    }

    public function test_assignments_and_routes_eager_load_display_fields(): void
    {
        $program = Program::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'code' => 'TRP', 'name' => 'TR Prog', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'program_id' => $program->id, 'name' => 'Grade 6', 'level_no' => 6]);
        $section = Section::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'class_id' => $class->id, 'name' => 'A', 'capacity' => 30]);
        $student = $this->makeStudent($this->lhr, 'TR-H');
        $student->update(['class_id' => $class->id, 'section_id' => $section->id]);

        $vehicle = $this->makeVehicle($this->lhr, 'Van-50', 20);
        $route = TransportRoute::create(['organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'Ring', 'code' => 'R-50', 'transport_vehicle_id' => $vehicle->id]);
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/transport/routes/{$route->id}/stops", ['name' => 'Libya', 'order_no' => 1]);
        $stop = TransportStop::where('transport_route_id', $route->id)->first();

        $this->as($this->admin, $this->lhr)->postJson('/api/v1/transport/assignments', [
            'student_id' => $student->id, 'transport_route_id' => $route->id, 'transport_stop_id' => $stop->id, 'kind' => 'both',
        ])->assertCreated();

        $row = $this->as($this->admin, $this->lhr)->getJson('/api/v1/transport/assignments')->assertOk()->json('data.0');
        $this->assertSame('TR-H', $row['student']['admission_no']);
        $this->assertSame('Ring', $row['route']['name']);
        $this->assertSame('Libya', $row['stop']['name']);
        $this->assertSame('Grade 6', $row['student']['school_class']['name']);
        $this->assertSame('A', $row['student']['section']['name']);

        $routeRow = $this->as($this->admin, $this->lhr)->getJson('/api/v1/transport/routes')->assertOk()->json('data.0');
        $this->assertSame('Van-50', $routeRow['vehicle']['code']);
        $this->assertSame(1, $routeRow['active_students_count']);
        $this->assertCount(1, $routeRow['stops']);

        $this->as($this->admin, $this->lhr)->getJson('/api/v1/transport/summary')->assertOk()
            ->assertJsonPath('data.routes', 1)
            ->assertJsonPath('data.active_students', 1)
            ->assertJsonPath('data.vehicles', 1)
            ->assertJsonPath('data.seats', 20);
    }

    // ---- Front desk -----------------------------------------------------

    public function test_pass_no_sequence_is_generated_per_day_per_campus(): void
    {
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/frontdesk/visitors', ['name' => 'A', 'purpose' => 'Meeting'])->assertCreated()
            ->assertJsonPath('data.pass_no', 'V-261021-01')->assertJsonPath('data.campus_id', $this->lhr);
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/frontdesk/visitors', ['name' => 'B', 'purpose' => 'Meeting'])->assertCreated()
            ->assertJsonPath('data.pass_no', 'V-261021-02');

        // A different campus keeps its own independent sequence for the same day.
        $this->as($this->admin, $this->isb)->postJson('/api/v1/frontdesk/visitors', ['name' => 'C', 'purpose' => 'Meeting'])->assertCreated()
            ->assertJsonPath('data.pass_no', 'V-261021-01')->assertJsonPath('data.campus_id', $this->isb);

        $this->as($this->admin, $this->lhr)->getJson('/api/v1/frontdesk/visitors')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_check_out_is_blocked_a_second_time(): void
    {
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/frontdesk/visitors', ['name' => 'D', 'purpose' => 'Fee'])->assertCreated();
        $visitor = \App\Models\VisitorLog::withoutGlobalScopes()->firstWhere('name', 'D');

        $this->as($this->admin, $this->lhr)->postJson("/api/v1/frontdesk/visitors/{$visitor->id}/checkout")->assertOk()->assertJsonPath('data.out_at', '2026-10-21 09:00:00');
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/frontdesk/visitors/{$visitor->id}/checkout")
            ->assertStatus(422)->assertJsonFragment(['message' => 'This visitor has already checked out.']);
    }

    public function test_frontdesk_store_requires_frontdesk_manage_permission(): void
    {
        // accounts_officer can view transport but has no front-desk rights at all.
        $this->as($this->accounts, $this->lhr)->postJson('/api/v1/frontdesk/visitors', ['name' => 'E', 'purpose' => 'X'])
            ->assertStatus(403);
        $this->assertSame(0, \App\Models\VisitorLog::withoutGlobalScopes()->count());
    }

    public function test_future_entry_and_reversed_times_are_rejected(): void
    {
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/frontdesk/visitors', [
            'name' => 'F', 'purpose' => 'P', 'in_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertStatus(422)->assertJsonFragment(['in_at' => ['The entry time cannot be in the future.']]);

        $this->as($this->admin, $this->lhr)->postJson('/api/v1/frontdesk/visitors', [
            'name' => 'G', 'purpose' => 'P', 'in_at' => '2026-10-21 08:00', 'out_at' => '2026-10-21 07:00',
        ])->assertStatus(422)->assertJsonFragment(['out_at' => ['The exit time cannot be before the entry time.']]);
    }

    public function test_summary_and_open_filter(): void
    {
        // Two visitors, one still inside.
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/frontdesk/visitors', ['name' => 'H', 'purpose' => 'Admission'])->assertCreated();
        $this->as($this->admin, $this->lhr)->postJson('/api/v1/frontdesk/visitors', ['name' => 'I', 'purpose' => 'Admission'])->assertCreated();
        $open = \App\Models\VisitorLog::withoutGlobalScopes()->firstWhere('name', 'H');
        $this->as($this->admin, $this->lhr)->postJson("/api/v1/frontdesk/visitors/{$open->id}/checkout")->assertOk();

        $summary = $this->as($this->admin, $this->lhr)->getJson('/api/v1/frontdesk/summary')->assertOk()->json('data');
        $this->assertSame(2, $summary['today_in']);
        $this->assertSame(1, $summary['today_out']);
        $this->assertSame(1, $summary['still_inside']);
        $this->assertSame('Admission', $summary['top_purposes'][0]['purpose']);
        $this->assertSame(2, $summary['top_purposes'][0]['count']);

        $this->as($this->admin, $this->lhr)->getJson('/api/v1/frontdesk/visitors?open=1')->assertOk()->assertJsonCount(1, 'data');
    }
}
