<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveApplication;
use App\Models\Setting;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HrTest extends TestCase
{
    use RefreshDatabase;

    private string $hr;       // hr_manager, campus LHR
    private string $admin;    // super admin, head office
    private string $accounts; // accounts_officer, campus LHR — no HR rights
    private string $teacher;
    private int $lhr;
    private int $isb;
    private Department $academic;
    private Designation $teacherDsg;
    private Staff $usman;
    private Staff $isbStaff;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 09:00:00');

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
        $this->hr = $login('hr.lhr@sunrise.edu.pk');
        $this->admin = $login('admin@nationcampus.edu.pk');
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');
        $this->teacher = $login('teacher.lhr@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;

        $this->academic = Department::create([
            'organization_id' => 1, 'name' => 'Academic', 'code' => 'ACAD', 'is_active' => true,
        ]);
        $this->teacherDsg = Designation::create([
            'organization_id' => 1, 'name' => 'Senior Teacher', 'category' => 'teaching',
            'grade' => 'BPS-17', 'department_id' => $this->academic->id,
        ]);

        $this->usman = $this->makeStaff('Usman', 'Raza', $this->lhr, 125000, 35, 12000);
        $this->isbStaff = $this->makeStaff('Hira', 'Siddiqui', $this->isb, 121000, 35, 12000);
    }

    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    private function makeStaff(string $first, string $last, int $campusId, float $basic, float $houseRent, float $medical): Staff
    {
        return Staff::create([
            'employee_no' => 'T-' . $campusId . '-' . (++$this->seq),
            'organization_id' => 1, 'campus_id' => $campusId,
            'first_name' => $first, 'last_name' => $last, 'gender' => 'female',
            'designation_id' => $this->teacherDsg->id, 'department_id' => $this->academic->id,
            'joined_on' => '2021-04-05', 'base_salary' => $basic,
            'house_rent_percent' => $houseRent, 'medical_allowance' => $medical,
            'status' => 'active',
        ]);
    }

    public function test_staff_records_require_a_campus_and_get_sequential_employee_numbers(): void
    {
        $this->as($this->admin)->postJson('/api/v1/staff', [
            'first_name' => 'No', 'last_name' => 'Campus', 'gender' => 'male', 'joined_on' => '2026-01-01',
            'base_salary' => 50000,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Select a campus before adding staff.']);

        $this->as($this->hr)->postJson('/api/v1/staff', [
            'first_name' => 'Talal', 'last_name' => 'Hussain', 'gender' => 'male', 'joined_on' => '2022-09-01',
            'base_salary' => 82000, 'house_rent_percent' => 30, 'medical_allowance' => 8000,
            'designation_id' => $this->teacherDsg->id, 'department_id' => $this->academic->id,
        ])->assertCreated()->assertJsonPath('data.employee_no', 'S-LHR-0001')
            ->assertJsonPath('data.campus_id', $this->lhr);

        $this->as($this->hr)->postJson('/api/v1/staff', [
            'first_name' => 'Saima', 'last_name' => 'Butt', 'gender' => 'female', 'joined_on' => '2023-04-10',
            'base_salary' => 78000,
        ])->assertCreated()->assertJsonPath('data.employee_no', 'S-LHR-0002');

        // The directory exposes the derived gross salary, never the raw CNIC.
        $this->as($this->hr)->getJson('/api/v1/staff')
            ->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.data.2.monthly_gross', 180750);
    }

    public function test_cnic_is_encrypted_at_rest(): void
    {
        $this->as($this->hr)->postJson('/api/v1/staff', [
            'first_name' => 'Naveed', 'last_name' => 'Akhtar', 'gender' => 'male',
            'joined_on' => '2021-11-01', 'base_salary' => 54000, 'cnic' => '35202-1234567-1',
        ])->assertCreated();

        $staff = Staff::firstWhere('last_name', 'Akhtar');
        $this->assertSame('35202-1234567-1', $staff->cnic);
        $raw = (string) DB::table('staff')->where('id', $staff->id)->value('cnic');
        $this->assertStringNotContainsString('1234567', $raw);
    }

    public function test_leave_days_skip_the_configured_week_off(): void
    {
        // 2026-10-05 is a Monday and 2026-10-11 a Sunday: six countable days, Sunday excluded.
        $this->as($this->hr)->postJson('/api/v1/leave-applications', [
            'staff_id' => $this->usman->id, 'type' => 'annual',
            'from_date' => '2026-10-05', 'to_date' => '2026-10-11', 'reason' => 'House shifting.',
        ])->assertCreated()->assertJsonPath('data.days', '6.00')
            ->assertJsonPath('data.status', 'pending');

        // Peshawar campuses take Sunday off too, but a campus can move the week off to Friday.
        Setting::put(1, $this->lhr, 'hr.week_off', 'friday');
        $this->as($this->hr)->postJson('/api/v1/leave-applications', [
            'staff_id' => $this->usman->id, 'type' => 'casual',
            'from_date' => '2026-10-19', 'to_date' => '2026-10-24', 'reason' => 'Training workshop.',
        ])->assertCreated()->assertJsonPath('data.days', '5.00');
    }

    public function test_overlapping_and_far_future_leave_is_rejected(): void
    {
        $this->as($this->hr)->postJson('/api/v1/leave-applications', [
            'staff_id' => $this->usman->id, 'type' => 'sick',
            'from_date' => '2026-11-02', 'to_date' => '2026-11-06', 'reason' => 'Minor surgery.',
        ])->assertCreated();

        $this->as($this->hr)->postJson('/api/v1/leave-applications', [
            'staff_id' => $this->usman->id, 'type' => 'annual',
            'from_date' => '2026-11-05', 'to_date' => '2026-11-12', 'reason' => 'Overlapping trip.',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'This staff member already has leave in that period.']);

        $this->as($this->hr)->postJson('/api/v1/leave-applications', [
            'staff_id' => $this->usman->id, 'type' => 'annual',
            'from_date' => '2027-06-01', 'to_date' => '2027-06-10', 'reason' => 'Half a year ahead.',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Leave cannot be applied more than 90 days in advance.']);

        // A resigned employee cannot apply.
        $this->usman->update(['status' => 'resigned', 'resigned_on' => '2026-10-01']);
        $this->as($this->hr)->postJson('/api/v1/leave-applications', [
            'staff_id' => $this->usman->id, 'type' => 'casual',
            'from_date' => '2026-10-20', 'to_date' => '2026-10-20', 'reason' => 'Renewal of documents.',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Staff member is no longer in service.']);
    }

    public function test_leave_approval_workflow(): void
    {
        $leave = LeaveApplication::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'staff_id' => $this->usman->id,
            'type' => 'earned', 'from_date' => '2026-10-26', 'to_date' => '2026-10-30',
            'days' => 5, 'reason' => 'Umrah with parents.', 'status' => 'pending',
        ]);

        $this->as($this->accounts)->postJson("/api/v1/leave-applications/{$leave->id}/decide", ['decision' => 'approved'])
            ->assertStatus(403);

        $this->as($this->hr)->postJson("/api/v1/leave-applications/{$leave->id}/decide", ['decision' => 'approved', 'notes' => 'Cover arranged.'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by', \App\Models\User::firstWhere('email', 'hr.lhr@sunrise.edu.pk')->id);

        $this->as($this->hr)->postJson("/api/v1/leave-applications/{$leave->id}/decide", ['decision' => 'rejected'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Application already approved.']);
    }

    public function test_hr_data_is_campus_scoped(): void
    {
        LeaveApplication::create([
            'organization_id' => 1, 'campus_id' => $this->isb, 'staff_id' => $this->isbStaff->id,
            'type' => 'casual', 'from_date' => '2026-10-20', 'to_date' => '2026-10-21',
            'days' => 2, 'reason' => 'Personal.', 'status' => 'pending',
        ]);

        $this->as($this->hr)->getJson('/api/v1/staff')->assertOk()->assertJsonCount(1, 'data.data');
        $this->as($this->hr)->getJson('/api/v1/leave-applications')->assertOk()->assertJsonCount(0, 'data');

        // The Islamabad application is invisible — and unauthorisable — from Lahore.
        $isbLeave = LeaveApplication::withoutGlobalScopes()->firstWhere('campus_id', $this->isb);
        $this->as($this->hr)->postJson("/api/v1/leave-applications/{$isbLeave->id}/decide", ['decision' => 'approved'])
            ->assertNotFound();

        // Head office sees both campuses unless it narrows to one.
        $this->as($this->admin)->getJson('/api/v1/staff')->assertOk()->assertJsonCount(2, 'data.data');
        $this->as($this->admin)->withHeaders(['X-Campus-Id' => $this->isb])->getJson('/api/v1/staff')
            ->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_leave_balance_is_measured_against_the_configured_entitlement(): void
    {
        foreach ([['annual', '2026-02-02', '2026-02-06', 5], ['annual', '2026-07-06', '2026-07-10', 5]] as [$type, $from, $to, $days]) {
            LeaveApplication::create([
                'organization_id' => 1, 'campus_id' => $this->lhr, 'staff_id' => $this->usman->id,
                'type' => $type, 'from_date' => $from, 'to_date' => $to, 'days' => $days,
                'reason' => 'Approved earlier.', 'status' => 'approved',
            ]);
        }

        $summary = $this->as($this->hr)->getJson("/api/v1/staff/{$this->usman->id}")
            ->assertOk()->assertJsonPath('data.monthly_gross', 180750)->json('data.leave_summary');

        $annual = collect($summary)->firstWhere('type', 'annual');
        $this->assertSame(14, $annual['allowed']);
        $this->assertSame(10, $annual['used']);
        $this->assertSame(4, $annual['balance']);

        $this->as($this->hr)->getJson('/api/v1/hr/summary')->assertOk()
            ->assertJsonPath('data.headcount', 1)
            ->assertJsonPath('data.active', 1)
            ->assertJsonPath('data.monthly_cost', 125000)
            ->assertJsonPath('data.departments.0.staff', 1);
    }

    public function test_hr_routes_require_hr_or_staff_permissions(): void
    {
        $this->as($this->teacher)->getJson('/api/v1/staff')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/departments')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/hr/summary')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/leave-applications')->assertStatus(403);

        $this->as($this->hr)->getJson('/api/v1/departments')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->hr)->getJson('/api/v1/designations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_departments_and_designations_can_be_created(): void
    {
        $this->as($this->hr)->postJson('/api/v1/departments', ['name' => 'Finance & Accounts', 'code' => 'FIN'])
            ->assertCreated()->assertJsonPath('data.organization_id', 1);

        $this->as($this->hr)->postJson('/api/v1/designations', [
            'name' => 'Accounts Officer', 'category' => 'administrative',
            'department_id' => Department::firstWhere('code', 'FIN')->id,
            'min_salary' => 80000, 'max_salary' => 60000,
        ])->assertStatus(422);

        $this->as($this->hr)->postJson('/api/v1/designations', [
            'name' => 'Accounts Officer', 'category' => 'administrative', 'min_salary' => 80000, 'max_salary' => 130000,
        ])->assertCreated()->assertJsonPath('data.campus_id', $this->lhr);
    }
}
