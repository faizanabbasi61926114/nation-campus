<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Campus;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10a — the family portal. These tests are deliberately written around two
 * households on the same campus, because campus scoping alone cannot separate them.
 */
class GuardianPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $guardianA;
    private User $guardianB;
    private string $tokenA;
    private string $tokenB;
    private string $teacher;
    private Student $wardA;
    private Student $wardB;
    private Student $unlinked;
    private Section $section;
    private Exam $exam;
    private int $lhrId;
    private string $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->month = now()->format('Y-m');
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        $this->teacher = $this->login('teacher.lhr@sunrise.edu.pk');

        $this->lhrId = Campus::firstWhere('code', 'LHR')->id;
        $program = Program::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'code' => 'FAM', 'name' => 'Family Prog', 'level' => 'matric']);
        $class = SchoolClass::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'program_id' => $program->id, 'name' => 'Grade 9', 'level_no' => 9]);
        $this->section = Section::create(['organization_id' => 1, 'campus_id' => $this->lhrId, 'class_id' => $class->id, 'name' => 'A']);

        $mk = fn ($no, $name) => Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'admission_no' => $no, 'first_name' => $name,
            'last_name' => 'K', 'gender' => 'female', 'dob' => '2012-01-01', 'father_name' => 'F',
            'class_id' => $class->id, 'section_id' => $this->section->id, 'status' => 'active',
        ]);
        $this->wardA = $mk('FAM-A', 'Ayesha');
        $this->wardB = $mk('FAM-B', 'Bilal');
        $this->unlinked = $mk('FAM-C', 'Sana');

        // Two households, same class — the isolation that campus tenancy cannot give.
        $this->guardianA = $this->household('guardian-a@family.test', 'Parent A', $this->wardA);
        $this->guardianB = $this->household('guardian-b@family.test', 'Parent B', $this->wardB);
        $this->tokenA = $this->login('guardian-a@family.test');
        $this->tokenB = $this->login('guardian-b@family.test');

        $this->rollCall($this->wardA, ['present', 'present', 'late', 'absent']);
        $this->rollCall($this->wardB, ['absent', 'absent', 'absent', 'absent']);

        $structure = FeeStructure::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'name' => 'Tuition — Grade 9',
            'category' => 'tuition', 'amount' => 3000, 'gst_percent' => 0, 'frequency' => 'monthly',
        ]);
        $this->invoice($structure, $this->wardA, 3000, 'partial', $this->day(4, -1));
        $this->invoice($structure, $this->wardB, 5000, 'unpaid', $this->day(4, 1));

        $this->exam = Exam::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'name' => 'Mid Term 2026', 'type' => 'mid_term',
            'start_date' => now()->subDays(10)->toDateString(), 'status' => 'published',
        ]);
        $eng = Subject::create(['organization_id' => 1, 'code' => 'FAM-ENG', 'name' => 'English'])->id;
        $mth = Subject::create(['organization_id' => 1, 'code' => 'FAM-MTH', 'name' => 'Mathematics'])->id;
        foreach ([$eng, $mth] as $sid) {
            ExamSubject::create(['exam_id' => $this->exam->id, 'subject_id' => $sid, 'max_marks' => 100, 'pass_marks' => 40]);
        }
        $this->markResults($this->wardA, [70, 80]);
        $this->markResults($this->wardB, [30, 35]);
    }

    public function test_wards_returns_only_this_households_children(): void
    {
        $wards = $this->as($this->tokenA)->getJson('/api/v1/guardian/wards')
            ->assertOk()->assertJsonCount(1, 'data')->json('data');

        $this->assertSame('Ayesha K', $wards[0]['name']);
        $this->assertSame($this->wardA->id, $wards[0]['id']);
        $this->assertSame('Grade 9', $wards[0]['class']);
        $this->assertSame('LHR', $wards[0]['campus']['code']);
        // Money and percentages travel as JSON numbers, so whole ones decode as ints.
        $this->assertEquals(75.0, $wards[0]['attendance']['percentage']);
        $this->assertEquals(2000.0, $wards[0]['fees']['balance']);
        $this->assertTrue($wards[0]['fees']['alert']);
        $this->assertEquals(75.0, $wards[0]['latest_result']['percentage']);

        // The other parent sees the sibling picture, never Ayesha's numbers.
        $other = $this->as($this->tokenB)->getJson('/api/v1/guardian/wards')->assertOk()->json('data.0');
        $this->assertSame('Bilal K', $other['name']);
        $this->assertEquals(5000.0, $other['fees']['balance']);
        $this->assertFalse($other['fees']['alert']);
    }

    public function test_a_guardian_reading_a_foreign_student_id_gets_404(): void
    {
        foreach (['attendance', 'fees', 'results'] as $endpoint) {
            $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->wardB->id}/$endpoint")->assertNotFound();
            $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->unlinked->id}/$endpoint")->assertNotFound();
            $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/999999/$endpoint")->assertNotFound();
        }
    }

    public function test_attendance_month_detail_and_trend(): void
    {
        $data = $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->wardA->id}/attendance")
            ->assertOk()->assertJsonPath('data.ward.name', 'Ayesha K')->json('data');

        $this->assertSame($this->month, $data['month']);
        $this->assertEquals(75.0, $data['summary']['percentage']);
        $this->assertSame([3, 1, 4], [$data['summary']['present'], $data['summary']['absent'], $data['summary']['total']]);
        $this->assertSame(1, $data['summary']['late']);
        $this->assertFalse($data['summary']['warning']);          // 75% is not below the default 75 threshold
        $this->assertCount(4, $data['days']);
        $this->assertSame('absent', $data['days'][0]['status']);   // newest day first
        $this->assertCount(1, $data['trend']);
        $this->assertSame($this->month, $data['trend'][0]['month']);

        // Bilal's parent is warned at 0%.
        $this->as($this->tokenB)->getJson("/api/v1/guardian/wards/{$this->wardB->id}/attendance")
            ->assertOk()->assertJsonPath('data.summary.warning', true)->assertJsonPath('data.summary.percentage', 0);

        // A month with no roll call reads as empty, not as an error.
        $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->wardA->id}/attendance?month=2001-01")
            ->assertOk()->assertJsonPath('data.summary', null)->assertJsonCount(0, 'data.days');
        $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->wardA->id}/attendance?month=nonsense")
            ->assertStatus(422);
    }

    public function test_fee_invoices_and_payments_are_this_childs_only(): void
    {
        $data = $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->wardA->id}/fees")
            ->assertOk()->json('data');

        $this->assertEquals(3000.0, $data['summary']['billed']);
        $this->assertEquals(1000.0, $data['summary']['paid']);
        $this->assertEquals(2000.0, $data['summary']['balance']);
        $this->assertEquals(2000.0, $data['summary']['overdue_balance']);
        $this->assertCount(1, $data['invoices']);
        $this->assertTrue($data['invoices'][0]['is_overdue']);
        $this->assertSame('Tuition — Grade 9', $data['invoices'][0]['title']);
        $this->assertEquals(1000.0, $data['invoices'][0]['payments'][0]['amount']);
        $this->assertSame('FAM-RCPT-1', $data['invoices'][0]['payments'][0]['receipt_no']);

        $other = $this->as($this->tokenB)->getJson("/api/v1/guardian/wards/{$this->wardB->id}/fees")->assertOk()->json('data');
        $this->assertEquals(5000.0, $other['summary']['balance']);
        $this->assertSame([], $other['payments']);
        $this->assertFalse($other['invoices'][0]['is_overdue']);
    }

    public function test_published_results_match_the_schools_report_card(): void
    {
        $data = $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->wardA->id}/results")
            ->assertOk()->json('data');

        $this->assertCount(1, $data['results']);
        $result = $data['results'][0];
        $this->assertSame('Mid Term 2026', $result['exam']);
        $this->assertEquals(75.0, $result['percentage']);
        $this->assertSame('Pass', $result['result']);
        $this->assertNotEmpty($result['grade']);
        $this->assertCount(2, $result['subjects']);
        $this->assertSame('English', $result['subjects'][0]['subject']);
        $this->assertEquals(70.0, $result['subjects'][0]['marks_obtained']);

        // The same paper read from the staff side must agree with what the parent sees.
        $card = $this->as($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/report-card?student_id={$this->wardA->id}")
            ->assertOk()->json('data');
        $this->assertSame($card['percentage'], $result['percentage']);
        $this->assertSame($card['grade'], $result['grade']);

        $fail = $this->as($this->tokenB)->getJson("/api/v1/guardian/wards/{$this->wardB->id}/results")->assertOk()->json('data.results.0');
        $this->assertSame('Fail (2 subject(s))', $fail['result']);
        $this->assertSame(32.5, $fail['percentage']);
    }

    public function test_results_stay_hidden_until_the_paper_is_published(): void
    {
        $this->exam->update(['status' => 'completed']);
        $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$this->wardA->id}/results")
            ->assertOk()->assertJsonCount(0, 'data.results');
        $this->as($this->tokenA)->getJson('/api/v1/guardian/wards')
            ->assertOk()->assertJsonPath('data.0.latest_result', null);
    }

    public function test_permission_and_authentication_gates(): void
    {
        // A class teacher has no family records and no guardian.view permission.
        $this->as($this->teacher)->getJson('/api/v1/guardian/wards')->assertForbidden();
        $this->as($this->teacher)->getJson("/api/v1/guardian/wards/{$this->wardA->id}/fees")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/v1/guardian/wards')->assertUnauthorized();
    }

    public function test_a_link_to_another_organization_does_not_open_a_door(): void
    {
        // Simulates a bad write: another tenant's ward pointing at this user.
        $rival = Organization::create(['code' => 'RIVAL', 'name' => 'Rival Education Network']);
        $foreign = Student::create([
            'organization_id' => $rival->id, 'campus_id' => $this->lhrId, 'admission_no' => 'FAM-ORG2', 'first_name' => 'Other',
            'last_name' => 'Tenant', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F',
            'status' => 'active', 'guardian_user_id' => $this->guardianA->id,
        ]);
        $this->as($this->tokenA)->getJson('/api/v1/guardian/wards')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->tokenA)->getJson("/api/v1/guardian/wards/{$foreign->id}/fees")->assertNotFound();
    }

    // ---------------------------------------------------------------------- fixtures

    public function test_a_guardian_answers_messages_but_does_not_run_the_mail_module(): void
    {
        $teacherId = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk')->id;
        $root = Message::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'subject' => 'About Ayesha',
            'body' => 'Please send the sick-day form.', 'sender_id' => $teacherId,
            'recipient_id' => $this->guardianA->id, 'channel' => 'in_app',
        ]);

        $this->as($this->tokenA)->postJson("/api/v1/messages/{$root->id}/reply", ['body' => 'Sending it tomorrow.'])
            ->assertCreated()->assertJsonPath('data.sender_id', $this->guardianA->id);
        $this->assertSame(2, Message::count());

        // Replying is not the same as broadcasting: no new threads, no announcements.
        $this->as($this->tokenA)->postJson('/api/v1/messages', [
            'recipient_id' => $teacherId, 'subject' => 'Hello', 'body' => 'Can I ask a question?',
        ])->assertForbidden();
        $this->as($this->tokenA)->postJson('/api/v1/announcements', [
            'title' => 'Closed', 'body' => 'School closed', 'audience' => 'guardians',
        ])->assertForbidden();
        $this->assertSame(2, Message::count());
    }

    public function test_a_guardians_contact_list_is_their_own_childrens_teachers(): void
    {
        $teacherId = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk')->id;
        $this->section->update(['class_teacher_id' => $teacherId]);

        $contacts = $this->as($this->tokenA)->getJson('/api/v1/messages/contacts')->assertOk()->json('data');
        $this->assertSame([$teacherId], array_column($contacts, 'id'));

        // The staff directory, with every other parent's address in it, stays shut.
        $this->as($this->tokenA)->getJson('/api/v1/messages/contacts')
            ->assertOk()->assertJsonPath('data.0.name', User::find($teacherId)->name);
        $staff = $this->as($this->login('principal.lhr@sunrise.edu.pk'))
            ->getJson('/api/v1/messages/contacts')->assertOk()->json('data');
        $this->assertGreaterThan(count($contacts), count($staff));
    }

    private function login(string $email): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        return $token;
    }

    private function household(string $email, string $name, Student $ward): User
    {
        $user = User::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'name' => $name, 'email' => $email,
            'password' => 'Password123!', 'phone' => '+92 300 0000000', 'is_active' => true,
        ]);
        $user->roleAssignments()->sync([Role::firstWhere('slug', 'guardian')->id => ['campus_id' => $this->lhrId]]);
        $ward->update(['guardian_user_id' => $user->id]);
        return $user;
    }

    /** Day-level roll call for the first days of this month, in the given order. */
    private function rollCall(Student $ward, array $statuses): void
    {
        foreach ($statuses as $i => $status) {
            AttendanceRecord::create([
                'organization_id' => 1, 'campus_id' => $this->lhrId,
                'subject_type' => 'student', 'subject_id' => $ward->id,
                'date' => sprintf('%s-%02d', $this->month, $i + 1), 'period_no' => 0,
                'status' => $status, 'marked_by' => null,
            ]);
        }
    }

    /** Day-of-month in a month offset from now, so due dates are reliably past or future. */
    private function day(int $dayOfMonth, int $monthOffset): string
    {
        return now()->startOfMonth()->addMonths($monthOffset)->format("Y-m") . sprintf('-%02d', $dayOfMonth);
    }

    private function invoice(FeeStructure $structure, Student $ward, float $amount, string $status, string $dueDate): FeeInvoice
    {
        $invoice = FeeInvoice::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'student_id' => $ward->id,
            'fee_structure_id' => $structure->id, 'title' => $structure->name,
            'issue_date' => now()->startOfMonth()->toDateString(), 'due_date' => $dueDate,
            'amount' => $amount, 'gst_amount' => 0, 'discount' => 0, 'late_fee' => 0,
            'status' => $status, 'session_month' => $this->month,
        ]);
        if ($status === 'partial') {
            FeePayment::create([
                'organization_id' => 1, 'campus_id' => $this->lhrId, 'invoice_id' => $invoice->id,
                'receipt_no' => 'FAM-RCPT-1', 'amount' => 1000, 'channel' => 'cash',
                'paid_on' => now()->toDateString(), 'status' => 'cleared',
            ]);
        }
        return $invoice;
    }

    private function markResults(Student $ward, array $marks): void
    {
        foreach ($this->exam->subjects()->get() as $i => $subject) {
            ExamMark::create([
                'organization_id' => 1, 'campus_id' => $this->lhrId, 'exam_id' => $this->exam->id,
                'exam_subject_id' => $subject->id, 'student_id' => $ward->id,
                'marks_obtained' => $marks[$i], 'is_absent' => false,
            ]);
        }
    }

    private function as(string $token): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        return $this;
    }
}
