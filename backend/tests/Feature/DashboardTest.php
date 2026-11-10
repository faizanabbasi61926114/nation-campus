<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Campus;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;       // super admin, head office, no campus
    private string $principal;   // LHR principal — almost every module
    private string $teacher;     // class teacher — students, attendance, exams, mail
    private string $accounts;    // accounts officer — money and students only
    private int $lhr;
    private int $isb;
    private int $seededStudents;   // pupils created by AcademicsSeeder, before the test data

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 09:00:00');

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\AcademicsSeeder::class);

        $login = function (string $email): string {
            $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123!'])->json('token');
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            return $token;
        };
        $this->admin = $login('admin@nationcampus.edu.pk');
        $this->principal = $login('principal.lhr@sunrise.edu.pk');
        $this->teacher = $login('teacher.lhr@sunrise.edu.pk');
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;
        $this->seededStudents = Student::withoutGlobalScopes()->count();
    }

    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    /** @return array<string,mixed> */
    private function dashboard(string $token, ?int $campus = null): array
    {
        return $this->as($token, $campus)->getJson('/api/v1/dashboard')->assertOk()->json('data');
    }

    private function student(string $campusCode, int $n, array $extra = []): Student
    {
        $campusId = $campusCode === 'LHR' ? $this->lhr : $this->isb;
        return Student::create($extra + [
            'organization_id' => 1, 'campus_id' => $campusId,
            'admission_no' => sprintf('S-%s-TEST-%03d', $campusCode, $n),
            'first_name' => 'Pupil' . $n, 'last_name' => $campusCode, 'gender' => 'male',
            'dob' => '2012-05-05', 'father_name' => 'Father', 'guardian_phone' => '+92 300 5551111',
            'status' => 'active', 'admission_date' => now()->subDays(20)->toDateString(),
        ]);
    }

    private function mark(Student $s, string $date, string $status): void
    {
        AttendanceRecord::create([
            'organization_id' => 1, 'campus_id' => $s->campus_id, 'subject_type' => 'student',
            'subject_id' => $s->id, 'date' => $date, 'period_no' => 1, 'status' => $status,
        ]);
    }

    public function test_the_payload_carries_only_the_sections_the_role_may_see(): void
    {
        $keys = fn (string $token) => (function ($sections) { sort($sections); return $sections; })
            (array_keys($this->dashboard($token)['sections']));

        // Built from permissions, not filtered afterwards: a module the role cannot read
        // is absent from the payload, so no key can be mistaken for a zero.
        $this->assertSame(['accounts', 'fees', 'students'], $keys($this->accounts));
        $this->assertSame(['attendance', 'communication', 'exams', 'students'], $keys($this->teacher));
        $this->assertSame([
            'accounts', 'admissions', 'attendance', 'audit', 'communication', 'exams',
            'fees', 'gate', 'hr', 'payroll', 'students',
        ], $keys($this->principal));

        // Staff attendance and leave counts stay with HR-permitted viewers.
        $this->assertNull($this->dashboard($this->teacher)['sections']['attendance']['staff']);
        $this->assertSame(
            ['marked', 'present', 'headcount', 'unmarked', 'present_percent', 'on_leave'],
            array_keys($this->dashboard($this->principal)['sections']['attendance']['staff']),
        );
    }

    public function test_head_office_sees_every_campus_and_a_campus_sees_only_its_own(): void
    {
        $this->student('ISB', 901);
        $this->student('ISB', 902);
        $lhrStudents = Student::withoutGlobalScopes()->where('campus_id', $this->lhr)->where('status', 'active')->count();

        $ho = $this->dashboard($this->admin);
        $this->assertTrue($ho['scope']['cross_campus']);
        $this->assertNull($ho['scope']['campus']);

        $rows = collect($ho['campus_breakdown'])->keyBy('code');
        $this->assertCount(4, $rows);                     // HO, LHR, ISB, PSH
        $this->assertSame(2, $rows['ISB']['students']);   // Islamabad has no seeded pupils
        $this->assertSame($lhrStudents, $rows['LHR']['students']);
        $this->assertSame(0, $rows['PSH']['students']);
        $this->assertSame($this->seededStudents + 2, $ho['sections']['students']['active']);

        // A campus account cannot widen its own view, and gets no cross-campus table.
        $lhr = $this->dashboard($this->principal);
        $this->assertFalse($lhr['scope']['cross_campus']);
        $this->assertSame('LHR', $lhr['scope']['campus']['code']);
        $this->assertNull($lhr['campus_breakdown']);
        $this->assertSame($lhrStudents, $lhr['sections']['students']['active']);

        // The head-office switch is still honourable for an account assigned to it.
        $this->assertSame(2, $this->dashboard($this->admin, $this->isb)['sections']['students']['active']);

        $this->as($this->principal, $this->isb)->getJson('/api/v1/dashboard')
            ->assertStatus(403)->assertJsonFragment(['message' => 'Campus not accessible for this account.']);
    }

    public function test_attendance_today_the_risk_list_and_the_trend_come_from_the_marks(): void
    {
        $a = $this->student('LHR', 801);
        $b = $this->student('LHR', 802);
        $c = $this->student('LHR', 803);
        $lhrCount = Student::withoutGlobalScopes()->where('campus_id', $this->lhr)->where('status', 'active')->count();

        foreach (range(10, 1) as $back) {
            $date = now()->subDays($back)->toDateString();
            $this->mark($a, $date, $back <= 2 ? 'absent' : 'present');       // 8 of 10
            $this->mark($b, $date, $back <= 7 ? 'absent' : 'present');       // 3 of 10
        }
        $this->mark($a, now()->toDateString(), 'present');
        $this->mark($c, now()->toDateString(), 'absent');

        $attendance = $this->dashboard($this->principal)['sections']['attendance'];
        $this->assertSame(now()->toDateString(), $attendance['date']);
        $this->assertSame($lhrCount, $attendance['students_expected']);   // counted after the three pupils above
        $this->assertSame(2, $attendance['students_marked']);
        $this->assertSame($lhrCount - 2, $attendance['unmarked']);
        $this->assertSame(1, $attendance['present']);
        $this->assertSame(1, $attendance['absent']);
        $this->assertSame(50.0, (float) $attendance['present_percent']);

        // Only pupils with enough history are judged, so C (one mark) is not listed.
        $this->assertSame(75.0, (float) $attendance['at_risk']['threshold']);
        $this->assertSame(1, $attendance['at_risk']['count']);
        $this->assertSame($b->id, $attendance['at_risk']['students'][0]['student_id']);
        $this->assertSame(10, $attendance['at_risk']['students'][0]['days']);
        $this->assertSame(30.0, (float) $attendance['at_risk']['students'][0]['present_percent']);

        $this->assertCount(14, $attendance['trend']);
        $this->assertSame(now()->toDateString(), $attendance['trend'][13]['date']);
        $this->assertSame(50.0, (float) $attendance['trend'][13]['present_percent']);   // A present, C absent
        // Marks were created from 2026-10-09, so the three earlier days are blank.
        $this->assertNull($attendance['trend'][0]['present_percent']);
        $this->assertSame(100.0, (float) $attendance['trend'][3]['present_percent']);   // 10-09, both present
        $this->assertSame(50.0, (float) $attendance['trend'][6]['present_percent']);    // 10-12, B turns absent
        $this->assertSame(0.0, (float) $attendance['trend'][11]['present_percent']);    // 10-17, both absent
    }

    public function test_fee_outstanding_deducts_cleared_payments_and_flags_overdue(): void
    {
        $s = $this->student('LHR', 804);
        $open = fn (float $amount, float $gst, string $due, string $status) => FeeInvoice::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'student_id' => $s->id,
            'title' => 'Tuition October 2026', 'issue_date' => '2026-10-01', 'due_date' => $due,
            'amount' => $amount, 'gst_amount' => $gst, 'discount' => 0, 'late_fee' => 0, 'status' => $status,
        ]);

        $overdue = $open(5000, 0, '2026-10-01', 'unpaid');
        $partial = $open(4000, 500, now()->addDays(10)->toDateString(), 'partial');
        $open(3000, 0, now()->addDays(20)->toDateString(), 'paid');

        FeePayment::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'invoice_id' => $partial->id,
            'receipt_no' => 'R-0001', 'amount' => 1500, 'channel' => 'cash', 'status' => 'cleared',
            'paid_on' => now()->toDateString(),
        ]);
        // A reversed receipt is not money and must not reduce what is owed.
        FeePayment::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'invoice_id' => $overdue->id,
            'receipt_no' => 'R-0002', 'amount' => 900, 'channel' => 'cash', 'status' => 'reversed',
            'paid_on' => now()->toDateString(),
        ]);

        $fees = $this->dashboard($this->accounts)['sections']['fees'];
        $this->assertSame(8000.0, (float) $fees['outstanding']);   // 9500 billed on open invoices − 1500 paid
        $this->assertSame(2, $fees['outstanding_invoices']);
        $this->assertSame(1, $fees['overdue_invoices']);
        $this->assertSame(1500.0, (float) $fees['collected_this_month']);
        $this->assertSame(1, $fees['receipts_this_month']);
        $this->assertSame('PKR', $fees['currency']);
        $this->assertCount(6, $fees['collected_trend']);

        // Same rule per campus in the head-office table, so the two views add up.
        $row = collect($this->dashboard($this->admin)['campus_breakdown'])->firstWhere('code', 'LHR');
        $this->assertSame((float) $fees['outstanding'], (float) $row['fees_outstanding']);
        $this->assertSame(1500.0, (float) $row['fees_collected_month']);
    }

    public function test_alerts_are_ranked_and_never_leak_a_closed_module(): void
    {
        $s = $this->student('LHR', 805);
        FeeInvoice::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'student_id' => $s->id,
            'title' => 'Tuition September 2026', 'issue_date' => '2026-09-01', 'due_date' => '2026-09-10',
            'amount' => 4500, 'gst_amount' => 0, 'discount' => 0, 'late_fee' => 90, 'status' => 'unpaid',
        ]);

        // Nothing is marked today for the 25 pupils in this campus, so attendance leads.
        $alerts = $this->dashboard($this->principal)['alerts'];
        $this->assertSame('critical', $alerts[0]['level']);
        $this->assertSame('attendance', $alerts[0]['module']);
        $this->assertStringContainsString('not marked', $alerts[0]['text']);
        $this->assertSame('critical', $alerts[1]['level']);
        $this->assertSame('fees', $alerts[1]['module']);
        $this->assertStringContainsString('past their due date', $alerts[1]['text']);

        // The accounts officer is shown the same fee problem but no admissions or HR item.
        $mine = collect($this->dashboard($this->accounts)['alerts']);
        $this->assertSame('fees', $mine->first()['module']);
        $this->assertStringContainsString('4,590 outstanding', $mine->first()['text']);
        $this->assertEmpty($mine->pluck('module')->intersect([
            'admissions', 'attendance', 'hr', 'payroll', 'communication', 'audit',
        ])->all());
    }

    public function test_the_dashboard_requires_dashboard_view_permission(): void
    {
        $role = Role::create(['organization_id' => 1, 'name' => 'Notice reader', 'slug' => 'notice_reader']);
        $role->permissions()->sync(['communication.view']);

        $reader = User::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'No Dashboard',
            'email' => 'reader.lhr@sunrise.edu.pk', 'password' => 'Password123!', 'is_active' => true,
        ]);
        $reader->roleAssignments()->sync([$role->id => ['campus_id' => $this->lhr]]);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'reader.lhr@sunrise.edu.pk', 'password' => 'Password123!'])->json('token');

        $this->as($token)->getJson('/api/v1/dashboard')->assertStatus(403);
    }
}
