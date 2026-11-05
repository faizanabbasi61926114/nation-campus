<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Campus;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private string $teacher;
    private string $principalLhr;
    private string $admin;
    private Section $section;
    private Student $s1;
    private Student $s2;
    private string $markDate;

    protected function setUp(): void
    {
        parent::setUp();
        // A month that is entirely in the past: marking days 01-06 of the current
        // month would hit a future date, which the API rejects, whenever the suite
        // runs before the 6th.
        $this->markDate = now()->subMonth()->subDays(2)->toDateString();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\AcademicsSeeder::class);

        $this->teacher = $this->postJson('/api/v1/auth/login', ['email' => 'teacher.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->principalLhr = $this->postJson('/api/v1/auth/login', ['email' => 'principal.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->admin = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();

        $lhr = Campus::firstWhere('code', 'LHR')->id;
        $program = Program::create(['organization_id' => 1, 'campus_id' => $lhr, 'code' => 'ATT', 'name' => 'Att Prog', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => $lhr, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        $this->section = Section::create(['organization_id' => 1, 'campus_id' => $lhr, 'class_id' => $class->id, 'name' => 'C', 'capacity' => 30]);

        $make = fn ($no, $fn) => Student::create([
            'organization_id' => 1, 'campus_id' => $lhr, 'admission_no' => $no, 'first_name' => $fn, 'last_name' => 'Test',
            'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F', 'class_id' => $class->id, 'section_id' => $this->section->id, 'status' => 'active',
        ]);
        $this->s1 = $make('ATT-1', 'Alpha');
        $this->s2 = $make('ATT-2', 'Beta');
    }

    public function test_bulk_mark_is_idempotent_upsert(): void
    {
        $payload = ['date' => $this->markDate, 'subject_type' => 'student', 'records' => [
            ['subject_id' => $this->s1->id, 'status' => 'present'],
            ['subject_id' => $this->s2->id, 'status' => 'absent', 'remarks' => 'Sick'],
        ]];
        $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', $payload)->assertOk();
        $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', $payload)->assertOk();

        $this->assertSame(2, AttendanceRecord::count());
        $this->assertSame('present', AttendanceRecord::where('subject_id', $this->s1->id)->value('status'));
        $this->assertSame('Sick', AttendanceRecord::where('subject_id', $this->s2->id)->value('remarks'));

        // re-mark changes status, never duplicates
        $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'student',
            'records' => [['subject_id' => $this->s1->id, 'status' => 'late']],
        ])->assertOk();
        $this->assertSame(2, AttendanceRecord::count());
        $this->assertSame('late', AttendanceRecord::where('subject_id', $this->s1->id)->value('status'));
    }

    public function test_mark_rejects_future_dates_and_foreign_students(): void
    {
        $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => now()->addDays(2)->toDateString(), 'subject_type' => 'student',
            'records' => [['subject_id' => $this->s1->id, 'status' => 'present']],
        ])->assertStatus(422);

        $isb = Campus::firstWhere('code', 'ISB')->id;
        $foreign = Student::create([
            'organization_id' => 1, 'campus_id' => $isb, 'admission_no' => 'ATT-ISB', 'first_name' => 'Farah',
            'last_name' => 'K', 'gender' => 'female', 'dob' => '2012-01-01', 'father_name' => 'F', 'status' => 'active',
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'student',
            'records' => [['subject_id' => $foreign->id, 'status' => 'present']],
        ])->assertStatus(422);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_roster_reflects_marked_status(): void
    {
        $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'student', 'records' => [
                ['subject_id' => $this->s1->id, 'status' => 'present'],
            ],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $roster = $this->withToken($this->teacher)->getJson("/api/v1/attendance/roster?date={$this->markDate}&subject_type=student&section_id={$this->section->id}")
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $byId = collect($roster)->keyBy('id');
        $this->assertTrue($byId[$this->s1->id]['marked']);
        $this->assertSame('present', $byId[$this->s1->id]['status']);
        $this->assertFalse($byId[$this->s2->id]['marked']);
        $this->assertNull($byId[$this->s2->id]['status']);
    }

    public function test_monthly_percentage_and_warning_flag(): void
    {
        $month = substr($this->markDate, 0, 7);
        // s1: 4 present + 2 absent = 66.7% (below default 75 threshold)
        // s2: 6 present = 100%
        for ($d = 1; $d <= 6; $d++) {
            $date = sprintf('%s-%02d', $month, $d);
            $records = [
                ['subject_id' => $this->s1->id, 'status' => $d <= 4 ? 'present' : 'absent'],
                ['subject_id' => $this->s2->id, 'status' => 'present'],
            ];
            $this->app['auth']->forgetGuards();
            $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', [
                'date' => $date, 'subject_type' => 'student', 'records' => $records,
            ])->assertOk();
        }

        $this->app['auth']->forgetGuards();
        $rows = collect($this->withToken($this->teacher)->getJson("/api/v1/attendance/monthly?month=$month&subject_type=student&section_id={$this->section->id}")
            ->assertOk()->json('data'))->keyBy('subject_id');

        $this->assertSame(66.7, $rows[$this->s1->id]['percentage']);
        $this->assertTrue($rows[$this->s1->id]['warning']);
        $this->assertSame(100, $rows[$this->s2->id]['percentage']);
        $this->assertFalse($rows[$this->s2->id]['warning']);
        $this->assertSame('Alpha Test', $rows[$this->s1->id]['name']);
    }

    public function test_campus_scoping_is_enforced(): void
    {
        $this->withToken($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'student',
            'records' => [['subject_id' => $this->s1->id, 'status' => 'present']],
        ])->assertOk();

        // principal of ISB cannot see or mark LHR records
        $principalIsb = $this->postJson('/api/v1/auth/login', ['email' => 'principal.isb@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $month = substr($this->markDate, 0, 7);
        $this->withToken($principalIsb)->getJson("/api/v1/attendance/monthly?month=$month&subject_type=student&section_id={$this->section->id}")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->withToken($principalIsb)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'student',
            'records' => [['subject_id' => $this->s1->id, 'status' => 'absent']],
        ])->assertStatus(422);
        $this->resetLhrContext();
        $this->assertSame('present', AttendanceRecord::where('subject_id', $this->s1->id)->value('status'));

        // head-office user without a campus context must select one before marking
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin)->getJson("/api/v1/attendance/roster?date={$this->markDate}&subject_type=student&section_id={$this->section->id}")->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'student',
            'records' => [['subject_id' => $this->s1->id, 'status' => 'present']],
        ])->assertStatus(422);

        // ... but works with X-Campus-Id
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin)->withHeaders(['X-Campus-Id' => Campus::firstWhere('code', 'LHR')->id])
            ->getJson("/api/v1/attendance/roster?date={$this->markDate}&subject_type=student&section_id={$this->section->id}")->assertOk();
        $this->flushHeaders();
    }

    public function test_receptionist_without_mark_permission_is_rejected(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'reception.khi@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'student',
            'records' => [['subject_id' => $this->s1->id, 'status' => 'present']],
        ])->assertForbidden();
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_staff_attendance_round_trip(): void
    {
        $teacherId = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk')->id;
        $this->withToken($this->principalLhr)->postJson('/api/v1/attendance/mark', [
            'date' => $this->markDate, 'subject_type' => 'staff',
            'records' => [['subject_id' => $teacherId, 'status' => 'leave', 'remarks' => 'Training']],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->principalLhr)->getJson("/api/v1/attendance/history?subject_type=staff&subject_id=$teacherId")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'leave')
            ->assertJsonPath('data.0.remarks', 'Training');

        $this->app['auth']->forgetGuards();
        $roster = $this->withToken($this->principalLhr)->getJson("/api/v1/attendance/roster?date={$this->markDate}&subject_type=staff")->assertOk()->json('data');
        $this->assertContains($teacherId, array_column($roster, 'id'));
    }

    /** TenantContext is a singleton that keeps the last request's campus; direct model queries must reset it. */
    private function resetLhrContext(): void
    {
        $ctx = app(\App\Support\TenantContext::class);
        $ctx->organizationId = 1;
        $ctx->campus = Campus::firstWhere('code', 'LHR');
    }
}
