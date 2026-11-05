<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\Campus;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamsTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;
    private string $examController;
    private string $teacher;
    private int $lhrId;
    private Exam $exam;
    private Section $section;
    private Student $topper;
    private Student $average;
    private array $subjectIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        $this->admin = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->examController = $this->postJson('/api/v1/auth/login', ['email' => 'exams.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->teacher = $this->postJson('/api/v1/auth/login', ['email' => 'teacher.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();

        $this->lhrId = Campus::firstWhere('code', 'LHR')->id;
        foreach ([['T-ENG', 'Test English'], ['T-MTH', 'Test Math']] as [$code, $name]) {
            $this->subjectIds[] = Subject::create(['organization_id' => 1, 'code' => $code, 'name' => $name])->id;
        }
        $program = Program::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'code' => 'EXA', 'name' => 'Exam Prog', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        $this->section = Section::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'class_id' => $class->id, 'name' => 'A']);
        $mk = fn ($no, $name) => Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'admission_no' => $no, 'first_name' => $name,
            'last_name' => 'K', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F',
            'class_id' => $class->id, 'section_id' => $this->section->id, 'status' => 'active',
        ]);
        $this->topper = $mk('EX-1', 'Topper');
        $this->average = $mk('EX-2', 'Average');

        $this->exam = Exam::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'name' => 'Unit Test 1', 'type' => 'unit_test',
            'start_date' => '2026-10-05', 'status' => 'completed',
        ]);
        foreach ($this->subjectIds as $sid) {
            ExamSubject::create(['exam_id' => $this->exam->id, 'subject_id' => $sid, 'max_marks' => 100, 'pass_marks' => 40]);
        }
    }

    public function test_exam_creation_and_status_workflow(): void
    {
        $created = $this->withToken($this->examController)->postJson('/api/v1/exams', [
            'name' => 'Mid Term 2026', 'type' => 'mid_term', 'start_date' => '2026-11-01', 'end_date' => '2026-11-08',
            'subjects' => [['subject_id' => $this->subjectIds[0], 'max_marks' => 100], ['subject_id' => $this->subjectIds[1], 'max_marks' => 50, 'pass_marks' => 20]],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonCount(2, 'data.subjects')->json('data.id');

        // illegal jump draft -> published rejected, legal path works
        $this->app['auth']->forgetGuards();
        $this->withToken($this->examController)->putJson("/api/v1/exams/$created", ['status' => 'published'])->assertStatus(422);
        foreach (['scheduled', 'ongoing', 'completed', 'published'] as $st) {
            $this->app['auth']->forgetGuards();
            $this->withToken($this->examController)->putJson("/api/v1/exams/$created", ['status' => $st])->assertOk();
        }
        // marks locked after publication
        $this->app['auth']->forgetGuards();
        $this->withToken($this->examController)->postJson("/api/v1/exams/$created/marks", [
            'marks' => [['student_id' => $this->topper->id, 'exam_subject_id' => 1, 'marks_obtained' => 10]],
        ])->assertStatus(422);
    }

    public function test_marks_entry_validation_and_upsert(): void
    {
        $es = ExamSubject::where('exam_id', $this->exam->id)->pluck('id')->all();
        $payload = ['marks' => [
            ['student_id' => $this->topper->id, 'exam_subject_id' => $es[0], 'marks_obtained' => 95],
            ['student_id' => $this->topper->id, 'exam_subject_id' => $es[1], 'marks_obtained' => 85],
            ['student_id' => $this->average->id, 'exam_subject_id' => $es[0], 'marks_obtained' => 60],
        ]];
        $this->withToken($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/marks", $payload)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/marks", $payload)->assertOk();
        $this->assertSame(3, ExamMark::withoutGlobalScopes()->count());

        // over-max marks rejected
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/marks", [
            'marks' => [['student_id' => $this->topper->id, 'exam_subject_id' => $es[1], 'marks_obtained' => 150]],
        ])->assertStatus(422);
        // foreign exam_subject rejected
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/marks", [
            'marks' => [['student_id' => $this->topper->id, 'exam_subject_id' => 999, 'marks_obtained' => 10]],
        ])->assertStatus(422);
        $this->assertSame(3, ExamMark::withoutGlobalScopes()->count());
    }

    public function test_report_card_grading_and_publication(): void
    {
        $es = ExamSubject::where('exam_id', $this->exam->id)->orderBy('id')->pluck('id')->all();
        ExamMark::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'exam_id' => $this->exam->id, 'student_id' => $this->topper->id, 'exam_subject_id' => $es[0], 'marks_obtained' => 95]);
        ExamMark::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'exam_id' => $this->exam->id, 'student_id' => $this->topper->id, 'exam_subject_id' => $es[1], 'marks_obtained' => 45]);

        // unpublished: grade hidden
        $this->withToken($this->examController)->getJson("/api/v1/exams/{$this->exam->id}/report-card?student_id={$this->topper->id}")
            ->assertOk()->assertJsonPath('data.grade', null)->assertJsonPath('data.published', false)
            ->assertJsonPath('data.total_marks', 140)->assertJsonPath('data.percentage', 70);

        $this->exam->update(['status' => 'published']);
        $this->app['auth']->forgetGuards();
        $card = $this->withToken($this->examController)->getJson("/api/v1/exams/{$this->exam->id}/report-card?student_id={$this->topper->id}")
            ->assertOk()->assertJsonPath('data.published', true)->json('data');
        $this->assertSame('A', $card['grade']);           // 70% → A band from seeded scale
        $this->assertSame('Pass', $card['result']);       // 45 ≥ 40 pass marks
        $this->assertSame('D', $card['subjects'][1]['result']); // 45/100 → D band (40-49)

        // a failing subject flips the overall result
        ExamMark::withoutGlobalScopes()->where(['exam_id' => $this->exam->id, 'student_id' => $this->topper->id, 'exam_subject_id' => $es[1]])
            ->update(['marks_obtained' => 25]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->examController)->getJson("/api/v1/exams/{$this->exam->id}/report-card?student_id={$this->topper->id}")
            ->assertOk()->assertJsonPath('data.result', 'Fail (1 subject(s))');
    }

    public function test_merit_list_ranking_with_ties(): void
    {
        $es = ExamSubject::where('exam_id', $this->exam->id)->orderBy('id')->pluck('id')->all();
        $seed = [
            [$this->topper->id, [90, 80]],   // 85%
            [$this->average->id, [70, 60]],  // 65%
        ];
        $third = Student::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'admission_no' => 'EX-3', 'first_name' => 'Tie', 'last_name' => 'K', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F', 'section_id' => $this->section->id, 'status' => 'active']);
        $seed[] = [$third->id, [70, 60]];    // tie with average
        foreach ($seed as [$sid, $marks]) {
            foreach ($es as $i => $esId) {
                ExamMark::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'exam_id' => $this->exam->id, 'student_id' => $sid, 'exam_subject_id' => $esId, 'marks_obtained' => $marks[$i]]);
            }
        }

        $list = collect($this->withToken($this->examController)->getJson("/api/v1/exams/{$this->exam->id}/merit-list?section_id={$this->section->id}")
            ->assertOk()->assertJsonCount(3, 'data')->json('data'))->keyBy('id');
        $this->assertSame(1, $list[$this->topper->id]['position']);
        $this->assertSame(2, $list[$this->average->id]['position']);
        $this->assertSame(2, $list[$third->id]['position']); // tied students share position
        $this->assertSame(85, $list[$this->topper->id]['percentage']);
    }

    public function test_permission_gating(): void
    {
        // class_teacher may enter marks but not schedule exams
        $this->withToken($this->teacher)->postJson('/api/v1/exams', [
            'name' => 'Nope', 'type' => 'monthly', 'start_date' => '2026-11-01', 'subjects' => [],
        ])->assertForbidden();

        // receptionist cannot view exams at all
        $reception = $this->postJson('/api/v1/auth/login', ['email' => 'reception.khi@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($reception)->getJson('/api/v1/exams')->assertForbidden();
    }

    public function test_grading_scale_override_per_campus(): void
    {
        $this->withToken($this->admin)->putJson('/api/v1/grading-scales', [
            'campus_id' => $this->lhrId,
            'scales' => [['min_percent' => 85, 'grade' => 'O', 'remark' => 'Outstanding'], ['min_percent' => 0, 'grade' => 'Z', 'remark' => 'Low']],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->examController)->getJson('/api/v1/grading-scales')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.grade', 'O')
            ->assertJsonPath('data.1.grade', 'Z');

        // head-office default remains intact for other campuses
        $this->assertSame(7, \App\Models\GradingScale::withoutGlobalScopes()->whereNull('campus_id')->count());
    }
}
