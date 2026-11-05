<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Campus;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicsStudentsTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;
    private string $principalLhr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->admin = $this->postJson('/api/v1/auth/login', ['email' => 'admin@nationcampus.edu.pk', 'password' => 'Password123!'])->json('token');
        $this->principalLhr = $this->postJson('/api/v1/auth/login', ['email' => 'principal.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');
    }

    public function test_academic_structure_crud(): void
    {
        $p = $this->withToken($this->admin)->postJson('/api/v1/programs', [
            'name' => 'Intermediate (Pre-Medical)', 'code' => 'FSC-MED', 'level' => 'intermediate', 'board' => 'FBISE',
        ])->assertCreated()->json('data.id');
        $c = $this->withToken($this->admin)->postJson('/api/v1/classes', [
            'program_id' => $p, 'name' => 'First Year', 'level_no' => 11, 'campus_id' => Campus::firstWhere('code', 'LHR')->id,
        ])->assertCreated()->json('data.id');
        $subj = $this->withToken($this->admin)->postJson('/api/v1/subjects', ['name' => 'Biology', 'code' => 'BIO', 'group' => 'Science'])
            ->assertCreated()->json('data.id');
        $this->withToken($this->admin)->putJson("/api/v1/classes/$c/subjects", ['subjects' => [['subject_id' => $subj, 'is_compulsory' => true]]])->assertOk();
        $this->withToken($this->admin)->postJson("/api/v1/classes/$c/sections", ['name' => 'A', 'capacity' => 35])
            ->assertCreated()->assertJsonPath('data.campus_id', Campus::firstWhere('code', 'LHR')->id);
        $this->withToken($this->admin)->getJson('/api/v1/classes')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, SchoolClass::find($c)->subjects()->count());
    }

    public function test_student_lifecycle(): void
    {
        $program = Program::create(['organization_id' => 1, 'code' => 'MAT', 'name' => 'Matric', 'level' => 'matric', 'campus_id' => 2]);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => 2, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        $section = Section::create(['organization_id' => 1, 'campus_id' => 2, 'class_id' => $class->id, 'name' => 'A', 'capacity' => 40]);

        $student = $this->withToken($this->principalLhr)->postJson('/api/v1/students', [
            'first_name' => 'Areeba', 'last_name' => 'Sidra', 'gender' => 'female', 'dob' => '2012-02-02',
            'father_name' => 'Tariq Sidra', 'class_id' => $class->id, 'section_id' => $section->id,
        ])->assertCreated()->json('data');
        $this->assertSame(2, $student['campus_id'], 'campus auto-assigned from context');

        $this->withToken($this->principalLhr)->putJson('/api/v1/students/' . $student['id'], ['status' => 'transferred'])->assertOk();
        $this->assertSame('transferred', Student::find($student['id'])->status);

        $this->withToken($this->principalLhr)->getJson('/api/v1/students?status=transferred')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admission_workflow_and_enrollment(): void
    {
        $program = Program::create(['organization_id' => 1, 'code' => 'MAT2', 'name' => 'Matric II', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => 2, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        Section::create(['organization_id' => 1, 'campus_id' => 2, 'class_id' => $class->id, 'name' => 'B', 'capacity' => 40]);

        $app = $this->withToken($this->principalLhr)->postJson('/api/v1/admissions', [
            'applicant_name' => 'Bilal Aslam', 'gender' => 'male', 'dob' => '2011-05-05',
            'father_name' => 'Aslam Pervaiz', 'phone' => '+92 3001234567', 'previous_marks' => 86,
            'program_id' => $program->id, 'class_id' => $class->id,
        ])->assertCreated()->json('data');

        // invalid transition rejected
        $this->withToken($this->principalLhr)->postJson("/api/v1/admissions/{$app['id']}/transition", ['to' => 'enrolled'])->assertStatus(422);
        foreach (['screening', 'test', 'offered', 'accepted'] as $step) {
            $this->withToken($this->principalLhr)->postJson("/api/v1/admissions/{$app['id']}/transition", ['to' => $step])->assertOk();
        }
        $student = $this->withToken($this->principalLhr)->postJson("/api/v1/admissions/{$app['id']}/enroll", [])->assertCreated()->json('data');
        $this->assertSame('Bilal', $student['first_name']);
        $this->assertSame('enrolled', Admission::find($app['id'])->status);
        $this->assertSame($app['id'], Student::find($student['id'])->admission_id);
    }

    public function test_timetable_clash_detection(): void
    {
        $program = Program::create(['organization_id' => 1, 'code' => 'TT', 'name' => 'Test Prog', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => 2, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        $section = Section::create(['organization_id' => 1, 'campus_id' => 2, 'class_id' => $class->id, 'name' => 'A']);
        $s1 = Subject::create(['organization_id' => 1, 'code' => 'S1', 'name' => 'Sub One']);
        $s2 = Subject::create(['organization_id' => 1, 'code' => 'S2', 'name' => 'Sub Two']);

        $slot = ['section_id' => $section->id, 'subject_id' => $s1->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '08:40'];
        $this->withToken($this->principalLhr)->postJson('/api/v1/timetable', $slot)->assertCreated();
        $clash = ['section_id' => $section->id, 'subject_id' => $s2->id, 'day_of_week' => 1, 'start_time' => '08:20', 'end_time' => '09:00'];
        $this->withToken($this->principalLhr)->postJson('/api/v1/timetable', $clash)->assertStatus(422);
        $ok = ['section_id' => $section->id, 'subject_id' => $s2->id, 'day_of_week' => 1, 'start_time' => '08:40', 'end_time' => '09:20'];
        $this->withToken($this->principalLhr)->postJson('/api/v1/timetable', $ok)->assertCreated();
    }

    public function test_campus_scoping_on_students(): void
    {
        $program = Program::create(['organization_id' => 1, 'code' => 'X', 'name' => 'X', 'level' => 'matric']);
        $lhr = Campus::firstWhere('code', 'LHR')->id;
        $isb = Campus::firstWhere('code', 'ISB')->id;
        Student::create(['organization_id' => 1, 'campus_id' => $lhr, 'admission_no' => 'A1', 'first_name' => 'L', 'last_name' => 'S', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F']);
        Student::create(['organization_id' => 1, 'campus_id' => $isb, 'admission_no' => 'A2', 'first_name' => 'I', 'last_name' => 'S', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->principalLhr)->getJson('/api/v1/students')->assertOk()->assertJsonCount(1, 'data');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin)->getJson('/api/v1/students')->assertOk()->assertJsonCount(2, 'data');
    }
}
