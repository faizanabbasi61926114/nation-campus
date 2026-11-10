<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Message;
use App\Models\Program;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gate log and the alert it raises. Two things are being pinned down here: a tap
 * is written once per direction per day for the family, and the class register fills
 * the same gap when nobody was at the desk.
 */
class GateEntryTest extends TestCase
{
    use RefreshDatabase;

    private int $lhrId;
    private Student $ward;
    private User $guardian;
    private string $desk;
    private string $teacher;
    private string $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\AcademicsSeeder::class);
        $this->seed(\Database\Seeders\HrSeeder::class);

        $this->lhrId = Campus::firstWhere('code', 'LHR')->id;
        $program = Program::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'code' => 'GTE', 'name' => 'Gate Prog', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        $section = Section::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'class_id' => $class->id, 'name' => 'A', 'capacity' => 30]);

        $this->ward = Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'admission_no' => 'GTE-1', 'first_name' => 'Ayesha',
            'last_name' => 'K', 'gender' => 'female', 'dob' => '2012-01-01', 'father_name' => 'F',
            'class_id' => $class->id, 'section_id' => $section->id, 'status' => 'active',
        ]);

        $this->guardian = User::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'name' => 'Parent A', 'email' => 'gate-a@family.test',
            'password' => 'Password123!', 'is_active' => true,
        ]);
        $this->guardian->roleAssignments()->sync([Role::firstWhere('slug', 'guardian')->id => ['campus_id' => $this->lhrId]]);
        $this->ward->update(['guardian_user_id' => $this->guardian->id]);

        // The seeded receptionist sits at head office, so this campus needs its own desk.
        $desk = User::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'name' => 'Gate Desk', 'email' => 'gate-desk@sunrise.test',
            'password' => 'Password123!', 'is_active' => true,
        ]);
        $desk->roleAssignments()->sync([Role::firstWhere('slug', 'receptionist')->id => ['campus_id' => $this->lhrId]]);

        $this->desk = $this->login('gate-desk@sunrise.test');
        $this->teacher = $this->login('teacher.lhr@sunrise.edu.pk');
        $this->principal = $this->login('principal.lhr@sunrise.edu.pk');
    }

    public function test_a_gate_tap_writes_the_row_and_alerts_the_family_once(): void
    {
        $this->as($this->desk)->postJson('/api/v1/entries', [
            'student_id' => $this->ward->id, 'direction' => 'in', 'gate' => 'Main gate',
        ])->assertCreated()->assertJsonPath('data.direction', 'in')->assertJsonPath('data.notified', true);

        $this->assertSame(1, StudentEntry::where('student_id', $this->ward->id)->count());
        $alert = Message::where('recipient_id', $this->guardian->id)->sole();
        $this->assertSame('in_app', $alert->channel);
        $this->assertStringContainsString('Ayesha K entered the school', $alert->subject);
        $this->assertStringContainsString('Main gate', $alert->body);
        $this->assertFalse($alert->send_email);
        $this->assertFalse($alert->send_sms);

        // A second "In" is a correction, not news: the row lands, the family stays quiet.
        $this->as($this->desk)->postJson('/api/v1/entries', [
            'student_id' => $this->ward->id, 'direction' => 'in', 'gate' => 'Back gate',
        ])->assertCreated();
        $this->assertSame(2, StudentEntry::count());
        $this->assertSame(1, Message::where('recipient_id', $this->guardian->id)->count());

        // The first departure of the day is its own story, so it does alert.
        $this->as($this->desk)->postJson('/api/v1/entries', [
            'student_id' => $this->ward->id, 'direction' => 'out', 'remarks' => 'Early pickup by father',
        ])->assertCreated();
        $this->assertSame(2, Message::where('recipient_id', $this->guardian->id)->count());
        $this->assertStringContainsString('Early pickup', Message::where('recipient_id', $this->guardian->id)->latest('id')->value('body'));
    }

    public function test_an_unlinked_student_is_recorded_without_an_alert(): void
    {
        $this->ward->update(['guardian_user_id' => null]);
        $this->as($this->desk)->postJson('/api/v1/entries', [
            'student_id' => $this->ward->id, 'direction' => 'in',
        ])->assertCreated()->assertJsonPath('data.notified', false);
        $this->assertSame(0, Message::count());
    }

    public function test_the_gate_rejects_a_future_time_and_a_withdrawn_student(): void
    {
        $this->as($this->desk)->postJson('/api/v1/entries', [
            'student_id' => $this->ward->id, 'direction' => 'in', 'at' => now()->addHours(3)->toDateTimeString(),
        ])->assertStatus(422);

        $this->ward->update(['status' => 'withdrawn']);
        $this->as($this->desk)->postJson('/api/v1/entries', [
            'student_id' => $this->ward->id, 'direction' => 'in',
        ])->assertStatus(422);
        $this->assertSame(0, StudentEntry::count());
    }

    public function test_marking_a_register_fills_the_gate_log_for_a_child_nobody_tapped_in(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => now()->toDateString(), 'subject_type' => 'student', 'period_no' => 0,
            'records' => [['subject_id' => $this->ward->id, 'status' => 'present']],
        ])->assertOk();

        $entry = StudentEntry::where('student_id', $this->ward->id)->sole();
        $this->assertSame('roll_call', $entry->source);
        $this->assertSame('in', $entry->direction);
        $this->assertSame(1, Message::where('recipient_id', $this->guardian->id)->count());
    }

    public function test_a_gate_tap_means_the_register_does_not_duplicate_it(): void
    {
        $this->as($this->desk)->postJson('/api/v1/entries', ['student_id' => $this->ward->id, 'direction' => 'in'])->assertCreated();
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => now()->toDateString(), 'subject_type' => 'student',
            'records' => [['subject_id' => $this->ward->id, 'status' => 'late']],
        ])->assertOk();

        $this->assertSame(1, StudentEntry::count());
        $this->assertSame('front_desk', StudentEntry::sole()->source);
    }

    public function test_absent_children_and_past_days_leave_the_gate_log_alone(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => now()->toDateString(), 'subject_type' => 'student',
            'records' => [['subject_id' => $this->ward->id, 'status' => 'absent']],
        ])->assertOk();

        // Yesterday's roll call is history; it cannot claim a child arrived this morning.
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => now()->subDay()->toDateString(), 'subject_type' => 'student',
            'records' => [['subject_id' => $this->ward->id, 'status' => 'present']],
        ])->assertOk();

        $this->assertSame(0, StudentEntry::count());
        $this->assertSame(0, Message::count());
    }

    public function test_a_period_roll_does_not_write_the_gate(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => now()->toDateString(), 'subject_type' => 'student', 'period_no' => 3,
            'records' => [['subject_id' => $this->ward->id, 'status' => 'present']],
        ])->assertOk();
        $this->assertSame(0, StudentEntry::count());
    }

    public function test_summary_and_index_read_the_day(): void
    {
        $this->as($this->desk)->postJson('/api/v1/entries', ['student_id' => $this->ward->id, 'direction' => 'in', 'gate' => 'Main gate'])->assertCreated();
        $other = Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'admission_no' => 'GTE-2', 'first_name' => 'Bilal',
            'last_name' => 'K', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F', 'status' => 'active',
        ]);
        $this->as($this->desk)->postJson('/api/v1/entries', ['student_id' => $other->id, 'direction' => 'in'])->assertCreated();
        $this->as($this->desk)->postJson('/api/v1/entries', ['student_id' => $other->id, 'direction' => 'out'])->assertCreated();

        $summary = $this->as($this->desk)->getJson('/api/v1/entries/summary')->assertOk()->json('data');
        $this->assertSame(2, $summary['arrivals']);
        $this->assertSame(1, $summary['departures']);
        $this->assertSame(1, $summary['inside']);            // Ayesha is in; Bilal has already left
        $this->assertSame(1, $summary['families_alerted']);   // only Ayesha has a linked family
        $this->assertSame($summary['expected'] - 2, $summary['untapped']);

        $index = $this->as($this->desk)->getJson('/api/v1/entries')->assertOk()->json('data');
        $this->assertCount(3, $index);
        $this->assertSame('out', $index[0]['direction']);                       // newest tap first
        $this->assertSame('Bilal K', $index[0]['name']);

        $search = $this->as($this->desk)->getJson('/api/v1/entries?q=ayesha')->assertOk()->json('data');
        $this->assertCount(1, $search);
        $this->assertSame('Ayesha K', $search[0]['name']);
    }

    public function test_a_parent_reads_the_gate_log_for_their_own_child_only(): void
    {
        $this->as($this->desk)->postJson('/api/v1/entries', ['student_id' => $this->ward->id, 'direction' => 'in', 'gate' => 'Main gate'])->assertCreated();

        $token = $this->login('gate-a@family.test');
        $days = $this->as($token)->getJson("/api/v1/guardian/wards/{$this->ward->id}/entries")
            ->assertOk()->json('data.days');
        $this->assertCount(1, $days);
        $this->assertSame('inside', $days[0]['state']);
        $this->assertSame('Main gate', $days[0]['taps'][0]['gate']);
        $this->assertSame('in', $days[0]['taps'][0]['direction']);

        $wardInPayload = $this->as($token)->getJson('/api/v1/guardian/wards')->assertOk()->json('data.0.gate');
        $this->assertSame('in', $wardInPayload['direction']);

        $other = Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'admission_no' => 'GTE-9', 'first_name' => 'Other',
            'last_name' => 'K', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F', 'status' => 'active',
        ]);
        $this->as($token)->getJson("/api/v1/guardian/wards/{$other->id}/entries")->assertNotFound();
    }

    public function test_a_teacher_without_front_desk_rights_cannot_read_or_write_the_gate(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/entries', ['student_id' => $this->ward->id, 'direction' => 'in'])->assertForbidden();
        $this->as($this->teacher)->getJson('/api/v1/entries')->assertForbidden();
        $this->as($this->principal)->getJson('/api/v1/entries')->assertOk();
        $this->assertSame(0, StudentEntry::count());
    }

    // ------------------------------------------------------------------ helpers

    private function login(string $email): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        return $token;
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        return $this;
    }
}
