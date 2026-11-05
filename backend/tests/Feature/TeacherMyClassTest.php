<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Exam;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10b — the class teacher's own view. It answers "which class is mine"
 * from sections.class_teacher_id, a column the academics screen wrote and nothing read.
 */
class TeacherMyClassTest extends TestCase
{
    use RefreshDatabase;

    private string $teacher;
    private string $principal;
    private int $teacherId;
    private int $otherId;
    private Section $mine;
    private Section $alsoMine;
    private Section $someoneElses;
    private Student $s1;
    private Student $s2;
    private int $dayOfWeek;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        $this->teacher = $this->login('teacher.lhr@sunrise.edu.pk');
        $this->principal = $this->login('principal.lhr@sunrise.edu.pk');
        $this->teacherId = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk')->id;
        $this->otherId = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk')->id;
        $this->dayOfWeek = (int) now()->dayOfWeekIso;
        $this->today = now()->toDateString();

        $lhr = Campus::firstWhere('code', 'LHR')->id;
        $program = Program::create(['organization_id' => 1, 'campus_id' => $lhr, 'code' => 'MYC', 'name' => 'My Prog', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => $lhr, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        $section = fn (string $name, int $teacher) => Section::create([
            'organization_id' => 1, 'campus_id' => $lhr, 'class_id' => $class->id, 'name' => $name,
            'capacity' => 40, 'class_teacher_id' => $teacher,
        ]);
        $this->mine = $section('A', $this->teacherId);
        $this->alsoMine = $section('B', $this->teacherId);
        $this->someoneElses = $section('C', $this->otherId);

        $mk = fn ($no, $name, Section $s) => Student::create([
            'organization_id' => 1, 'campus_id' => $lhr, 'admission_no' => $no, 'first_name' => $name, 'last_name' => 'M',
            'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F',
            'class_id' => $s->class_id, 'section_id' => $s->id, 'status' => 'active',
        ]);
        $this->s1 = $mk('MYC-1', 'Alpha', $this->mine);
        $this->s2 = $mk('MYC-2', 'Beta', $this->mine);
        $mk('MYC-3', 'Gamma', $this->alsoMine);
        $mk('MYC-4', 'Delta', $this->someoneElses);

        $eng = Subject::create(['organization_id' => 1, 'code' => 'MYC-ENG', 'name' => 'English'])->id;
        $mth = Subject::create(['organization_id' => 1, 'code' => 'MYC-MTH', 'name' => 'Mathematics'])->id;
        // Period 1 of A belongs to someone else, periods 2 of A and 1 of B are mine.
        TimetableSlot::create(['organization_id' => 1, 'campus_id' => $lhr, 'section_id' => $this->mine->id,
            'subject_id' => $mth, 'teacher_id' => $this->otherId, 'day_of_week' => $this->dayOfWeek,
            'start_time' => '08:00', 'end_time' => '08:40']);
        TimetableSlot::create(['organization_id' => 1, 'campus_id' => $lhr, 'section_id' => $this->mine->id,
            'subject_id' => $eng, 'teacher_id' => $this->teacherId, 'day_of_week' => $this->dayOfWeek,
            'start_time' => '08:45', 'end_time' => '09:25', 'room' => 'Room 4']);
        TimetableSlot::create(['organization_id' => 1, 'campus_id' => $lhr, 'section_id' => $this->alsoMine->id,
            'subject_id' => $eng, 'teacher_id' => $this->teacherId, 'day_of_week' => $this->dayOfWeek,
            'start_time' => '09:30', 'end_time' => '10:10']);

        Exam::create(['organization_id' => 1, 'campus_id' => $lhr, 'name' => 'Monthly Test', 'type' => 'monthly',
            'start_date' => now()->subDays(2)->toDateString(), 'status' => 'completed']);
    }

    public function test_only_the_sections_i_am_class_teacher_of_are_listed(): void
    {
        $data = $this->as($this->teacher)->getJson('/api/v1/teacher/my-class')->assertOk()->json('data');
        $ids = collect($data['sections'])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->mine->id, $this->alsoMine->id], $ids);
        $this->assertNotContains($this->someoneElses->id, $ids);

        $a = collect($data['sections'])->firstWhere('id', $this->mine->id);
        $this->assertSame('Grade 9', $a['class']);
        $this->assertSame('A', $a['name']);
        $this->assertSame(2, $a['students']);
        $this->assertSame('LHR', $a['campus']['code']);
        $this->assertSame($this->today, $data['date']);

        // The principal's own answer is empty — this view is a personal one, not a campus list.
        $this->as($this->principal)->getJson('/api/v1/teacher/my-class')
            ->assertOk()->assertJsonCount(1, 'data.sections');     // principal is teacher of C
    }

    public function test_todays_periods_are_ranked_and_show_roll_progress(): void
    {
        $periods = $this->as($this->teacher)->getJson('/api/v1/teacher/my-class')->assertOk()->json('data.periods');

        $this->assertCount(2, $periods);
        $this->assertSame('08:45', $periods[0]['start_time']);
        $this->assertSame(2, $periods[0]['period_no']);            // 1 is the other teacher's slot
        $this->assertSame('Grade 9 A', $periods[0]['section']);
        $this->assertSame('English', $periods[0]['subject']);
        $this->assertSame('Room 4', $periods[0]['room']);
        $this->assertSame(2, $periods[0]['students']);
        $this->assertFalse($periods[0]['attendance_complete']);

        $this->assertSame(1, $periods[1]['period_no']);
        $this->assertSame('Grade 9 B', $periods[1]['section']);
    }

    public function test_roll_call_shows_up_as_soon_as_i_mark_it(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->today, 'subject_type' => 'student', 'records' => [
                ['subject_id' => $this->s1->id, 'status' => 'present'],
            ],
        ])->assertOk();

        $data = $this->as($this->teacher)->getJson('/api/v1/teacher/my-class')->assertOk()->json('data');
        $a = collect($data['sections'])->firstWhere('id', $this->mine->id);
        $this->assertSame(1, $a['attendance_marked_today']);
        $this->assertSame(1, $a['attendance_present_today']);
        $this->assertFalse($a['attendance_complete']);            // 2 boys, 1 marked

        $this->app['auth']->forgetGuards();
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->today, 'subject_type' => 'student', 'records' => [
                ['subject_id' => $this->s1->id, 'status' => 'present'],
                ['subject_id' => $this->s2->id, 'status' => 'absent'],
            ],
        ])->assertOk();

        $data = $this->as($this->teacher)->getJson('/api/v1/teacher/my-class')->assertOk()->json('data');
        $a = collect($data['sections'])->firstWhere('id', $this->mine->id);
        $this->assertSame(2, $a['attendance_marked_today']);
        $this->assertTrue($a['attendance_complete']);
    }

    public function test_a_period_marks_against_its_own_period_number(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->today, 'subject_type' => 'student', 'period_no' => 2,
            'records' => [['subject_id' => $this->s1->id, 'status' => 'present']],
        ])->assertOk();

        $periods = $this->as($this->teacher)->getJson('/api/v1/teacher/my-class')->assertOk()->json('data.periods');
        $this->assertSame(1, $periods[0]['attendance_marked']);   // period 2 of A
        $this->assertFalse($periods[0]['attendance_complete']);
        $this->assertSame(0, $periods[1]['attendance_marked']);   // period 1 of B untouched
    }

    public function test_marks_entry_offers_the_papers_that_are_not_published(): void
    {
        $data = $this->as($this->teacher)->getJson('/api/v1/teacher/my-class')->assertOk()->json('data');
        $this->assertSame('Monthly Test', $data['exams'][0]['name']);
        $this->assertSame('completed', $data['exams'][0]['status']);

        $this->exam_publish();
        $this->as($this->teacher)->getJson('/api/v1/teacher/my-class')
            ->assertOk()->assertJsonCount(0, 'data.exams');
    }

    public function test_the_roster_reads_back_the_sheet_i_asked_for(): void
    {
        $this->as($this->teacher)->postJson('/api/v1/attendance/mark', [
            'date' => $this->today, 'subject_type' => 'student', 'period_no' => 2,
            'records' => [['subject_id' => $this->s1->id, 'status' => 'absent']],
        ])->assertOk();

        $roster = fn (int $period) => $this->as($this->teacher)
            ->getJson("/api/v1/attendance/roster?date={$this->today}&subject_type=student&section_id={$this->mine->id}&period_no={$period}")
            ->assertOk()->json('data');

        $byId = collect($roster(2))->keyBy('id');
        $this->assertSame('absent', $byId->get($this->s1->id)['status'], 'The period sheet must show what was marked for that period.');
        $this->assertNull($byId->get($this->s2->id)['status']);

        // The day register is a separate sheet: marking a period does not fill it in.
        $day = collect($roster(0))->keyBy('id');
        $this->assertNull($day->get($this->s1->id)['status']);

        // Omitting the parameter keeps the old day-level behaviour the desktop screens use.
        $this->as($this->teacher)->getJson("/api/v1/attendance/roster?date={$this->today}&subject_type=student&section_id={$this->mine->id}")
            ->assertOk()->assertJsonPath('period_no', 0);
    }

    public function test_accounts_without_academics_view_are_refused(): void
    {
        $reception = $this->login('reception.khi@sunrise.edu.pk');
        $this->as($reception)->getJson('/api/v1/teacher/my-class')->assertForbidden();
    }

    private function exam_publish(): void
    {
        $exam = Exam::firstWhere('name', 'Monthly Test');
        $this->as($this->principal)->putJson("/api/v1/exams/{$exam->id}", ['status' => 'published'])->assertOk();
    }

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
