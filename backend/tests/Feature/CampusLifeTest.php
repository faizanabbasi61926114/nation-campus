<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\CertificateRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campus-life modules: certificates, the school calendar and the discipline register.
 * Rows are created at Lahore and Islamabad both, so every assertion doubles as a campus-scope check.
 */
class CampusLifeTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;      // super admin, head office (no campus unless one is asked for)
    private string $principal;  // LHR principal — documents, calendar and discipline, view + manage
    private string $teacher;    // class teacher — calendar.view, discipline.manage, no documents
    private string $accounts;   // accounts officer — none of the three
    private string $reception;  // receptionist — documents.view only
    private int $lhr;
    private int $isb;
    private int $principalId;
    private Student $ali;
    private Student $lastLhr;
    private Student $gradeTen;
    private Student $isbStudent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');

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
        $this->reception = $login('reception.khi@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;
        $this->principalId = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk')->id;

        $this->ali = Student::withoutGlobalScopes()->where('campus_id', $this->lhr)->orderBy('id')->first();
        $this->lastLhr = Student::withoutGlobalScopes()->where('campus_id', $this->lhr)->orderByDesc('id')->first();
        $this->gradeTen = Student::withoutGlobalScopes()->where('campus_id', $this->lhr)
            ->whereNot('class_id', $this->ali->class_id)->orderBy('id')->first();

        $this->isbStudent = Student::create([
            'organization_id' => 1, 'campus_id' => $this->isb, 'admission_no' => 'S-ISB-2026-9001',
            'first_name' => 'Bilal', 'last_name' => 'Iqbal', 'gender' => 'male',
            'dob' => '2011-05-19', 'father_name' => 'Iqbal Ahmed', 'guardian_phone' => '+92 300 9998877',
            'admission_date' => '2026-04-01', 'status' => 'active',
        ]);
    }

    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    /** @return array<string, mixed> */
    private function requestCertificate(Student $student, array $overrides = []): array
    {
        return $this->as($this->principal)->postJson('/api/v1/certificates', $overrides + [
            'student_id' => $student->id,
            'type' => 'bonafide',
            'purpose' => 'opening a savings account with Meezan Bank',
            'fee' => 300,
        ])->assertCreated()->json('data');
    }

    private function openCase(Student $student, array $overrides = []): array
    {
        return $this->as($this->principal)->postJson('/api/v1/discipline/cases', $overrides + [
            'student_id' => $student->id,
            'incident_on' => '2026-10-18',
            'category' => 'conduct',
            'severity' => 'minor',
            'description' => 'Shouted at a classmate during the morning break.',
        ])->assertCreated()->json('data');
    }

    // ------------------------------------------------------------------- certificates

    public function test_a_request_is_approved_then_issued_with_a_serial_number(): void
    {
        $row = $this->requestCertificate($this->ali);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($this->principalId, $row['requested_by']);
        $this->assertNull($row['reference_no']);

        // Issuing before a decision is refused in plain English.
        $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/issue")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Approve or reject this request before issuing the certificate.']);

        $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/decide", [
            'decision' => 'approved', 'remarks' => 'Fee challan attached.',
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by', $this->principalId);
        $this->assertNotNull(CertificateRequest::find($row['id'])->decided_at);

        // A decided request cannot be decided twice, and its details are locked.
        $again = $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/decide", ['decision' => 'rejected'])
            ->assertStatus(422);
        $this->assertStringContainsString('already been approved', (string) $again->json('message'));
        $this->assertStringContainsString('cannot be decided twice', (string) $again->json('message'));
        $this->as($this->principal)->putJson("/api/v1/certificates/{$row['id']}", ['purpose' => 'changed my mind'])->assertStatus(422);

        $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/issue")
            ->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.reference_no', 'CERT/LHR/0001');
        $this->assertNotNull(CertificateRequest::find($row['id'])->issued_at);

        $twice = $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/issue")->assertStatus(422);
        $this->assertStringContainsString('Already issued as CERT/LHR/0001', (string) $twice->json('message'));

        $second = $this->requestCertificate($this->lastLhr, ['type' => 'transfer']);
        $this->as($this->principal)->postJson("/api/v1/certificates/{$second['id']}/decide", ['decision' => 'approved'])->assertOk();
        $this->as($this->principal)->postJson("/api/v1/certificates/{$second['id']}/issue")
            ->assertOk()->assertJsonPath('data.reference_no', 'CERT/LHR/0002');

        // The sequence belongs to the organization, so a second campus continues from 0003.
        $third = $this->as($this->admin, $this->isb)->postJson('/api/v1/certificates', [
            'student_id' => $this->isbStudent->id, 'type' => 'character', 'purpose' => 'police verification',
        ])->assertCreated()->assertJsonPath('data.campus_id', $this->isb)->json('data');
        $this->as($this->admin, $this->isb)->postJson("/api/v1/certificates/{$third['id']}/decide", ['decision' => 'approved'])->assertOk();
        $this->as($this->admin, $this->isb)->postJson("/api/v1/certificates/{$third['id']}/issue")
            ->assertOk()->assertJsonPath('data.reference_no', 'CERT/ISB/0003');

        // A campus never sees another campus's register.
        $this->as($this->principal)->getJson('/api/v1/certificates')->assertOk()->assertJsonPath('data.total', 2);
        $this->as($this->principal)->getJson('/api/v1/certificates/summary')->assertOk()
            ->assertJsonPath('data.pending', 0)->assertJsonPath('data.approved', 0)
            ->assertJsonPath('data.rejected', 0)->assertJsonPath('data.issued', 2);

        // Without a campus context nothing can be raised.
        $this->as($this->admin)->postJson('/api/v1/certificates', [
            'student_id' => $this->ali->id, 'type' => 'bonafide', 'purpose' => 'no campus chosen',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Pick a campus before raising a certificate request.']);

        // The index filters by status and by student.
        $this->as($this->admin)->withHeaders(['X-Campus-Id' => $this->isb])
            ->getJson('/api/v1/certificates?status=pending')->assertOk()->assertJsonCount(0, 'data.data');
        $this->as($this->principal)->getJson('/api/v1/certificates?student_id=' . $this->ali->id)
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.reference_no', 'CERT/LHR/0001');
    }

    public function test_a_rejected_request_can_never_be_issued(): void
    {
        $row = $this->requestCertificate($this->ali, ['type' => 'other']);
        $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/decide", [
            'decision' => 'rejected', 'remarks' => 'The fee challan was not attached.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.remarks', 'The fee challan was not attached.');

        $blocked = $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/issue")->assertStatus(422);
        $this->assertStringContainsString('rejected request cannot be issued', (string) $blocked->json('message'));
        $this->assertNull(CertificateRequest::find($row['id'])->reference_no);

        $this->as($this->principal)->getJson('/api/v1/certificates/summary')->assertOk()
            ->assertJsonPath('data.rejected', 1)->assertJsonPath('data.issued', 0);
    }

    public function test_the_printed_certificate_is_assembled_from_the_live_student_record(): void
    {
        $row = $this->requestCertificate($this->ali, ['title' => 'To the Visa Officer, Embassy of the UAE']);
        $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/decide", ['decision' => 'approved'])->assertOk();
        $this->as($this->principal)->postJson("/api/v1/certificates/{$row['id']}/issue")->assertOk();

        $fullName = trim($this->ali->first_name . ' ' . $this->ali->last_name);
        $className = SchoolClass::find($this->ali->class_id)?->name;
        $print = $this->as($this->principal)->getJson("/api/v1/certificates/{$row['id']}/print")
            ->assertOk()->assertJsonPath('data.printable', true)->json('data');

        $this->assertSame('To the Visa Officer, Embassy of the UAE', $print['header']);
        foreach ([$fullName, $this->ali->admission_no, 'CERT/LHR/0001', $print['campus_name'], 'opening a savings account', $className] as $needle) {
            $this->assertStringContainsString((string) $needle, (string) $print['body']);
        }
        $this->assertStringContainsString('a bona fide student of ' . $className, (string) $print['body']);
        $this->assertStringContainsString('Dated 2026-10-21', (string) $print['text']);
        $this->assertSame($className, $print['class']);

        // Correcting the student profile reaches the wording, because nothing was stored.
        $this->ali->update(['first_name' => 'Rehan', 'last_name' => 'Aslam']);
        $reprinted = $this->as($this->principal)->getJson("/api/v1/certificates/{$row['id']}/print")->assertOk()->json('data');
        $this->assertStringContainsString('Rehan Aslam', (string) $reprinted['body']);
        $this->assertStringNotContainsString($fullName, (string) $reprinted['body']);

        // A student with no class or section yet still gets a printable certificate.
        $unplaced = Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'admission_no' => 'S-LHR-2026-9999',
            'first_name' => 'Zara', 'last_name' => 'Noor', 'gender' => 'female', 'dob' => '2012-02-02',
            'father_name' => 'Noor Ahmed', 'admission_date' => '2026-04-01', 'status' => 'active',
        ]);
        $draft = $this->requestCertificate($unplaced, ['purpose' => 'a railway concession']);
        $this->as($this->principal)->postJson("/api/v1/certificates/{$draft['id']}/decide", ['decision' => 'approved'])->assertOk();
        $this->as($this->principal)->postJson("/api/v1/certificates/{$draft['id']}/issue")->assertOk();
        $fallback = $this->as($this->principal)->getJson("/api/v1/certificates/{$draft['id']}/print")->assertOk()->json('data');
        $this->assertNull($fallback['class']);
        $this->assertNull($fallback['section']);
        $this->assertSame('Zara Noor', $fallback['student_name']);
        $this->assertStringContainsString('a student of this institution', (string) $fallback['body']);
        $this->assertStringContainsString('S-LHR-2026-9999', (string) $fallback['body']);
    }

    // ----------------------------------------------------------------------- calendar

    public function test_the_calendar_refuses_an_inverted_range_and_marks_organisation_wide_events(): void
    {
        $this->as($this->principal)->postJson('/api/v1/calendar', [
            'title' => 'Backwards', 'type' => 'event', 'start_date' => '2026-10-20', 'end_date' => '2026-10-10',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'The end date cannot fall before the start date.']);

        // Head office with no campus chosen leaves campus_id empty: that is what makes it shared.
        $shared = $this->as($this->admin)->postJson('/api/v1/calendar', [
            'title' => 'Eid Milad un-Nabi', 'type' => 'holiday', 'start_date' => '2026-11-05', 'end_date' => '2026-11-06',
            'audience' => 'all', 'campus_id' => null,
        ])->assertCreated()->assertJsonPath('data.campus_id', null)
            ->assertJsonPath('data.is_campus_wide', true)
            ->assertJsonPath('data.affects_attendance', true)   // a holiday shuts the campus by default
            ->json('data');

        $gala = $this->as($this->principal)->postJson('/api/v1/calendar', [
            'title' => 'Founders Day sports gala', 'type' => 'event', 'start_date' => '2026-10-26', 'audience' => 'all',
        ])->assertCreated()->assertJsonPath('data.campus_id', $this->lhr)
            ->assertJsonPath('data.is_campus_wide', false)
            ->assertJsonPath('data.affects_attendance', false)
            // A one-day event with no end date stores that same day.
            ->assertJsonPath('data.end_date', '2026-10-26')
            ->json('data');

        $this->as($this->principal)->postJson('/api/v1/calendar', [
            'title' => 'An optional holiday', 'type' => 'holiday', 'start_date' => '2026-10-30', 'affects_attendance' => false,
        ])->assertCreated()->assertJsonPath('data.affects_attendance', false);

        $this->as($this->principal)->postJson('/api/v1/calendar', [
            'title' => 'Faculty workshop', 'type' => 'meeting', 'start_date' => '2026-10-24', 'audience' => 'faculty',
        ])->assertCreated()->assertJsonPath('data.affects_attendance', false);

        $titles = fn (string $from, string $to) => collect(
            $this->as($this->principal)->getJson("/api/v1/calendar?from=$from&to=$to")->assertOk()->json('data')
        )->pluck('title')->all();

        // November holds the shared holiday only; October holds its own rows, ordered by start date.
        $this->assertSame(['Eid Milad un-Nabi'], $titles('2026-11-01', '2026-11-30'));
        $this->assertSame(['Faculty workshop', 'Founders Day sports gala', 'An optional holiday'], $titles('2026-10-01', '2026-10-31'));

        // No range at all means the current month.
        $this->as($this->principal)->getJson('/api/v1/calendar')->assertOk()
            ->assertJsonPath('range.from', '2026-10-01')->assertJsonPath('range.to', '2026-10-31')
            ->assertJsonCount(3, 'data');

        // The shared row is visible from Islamabad too, and marked as shared.
        $this->as($this->admin, $this->isb)->getJson('/api/v1/calendar?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_campus_wide', true);

        $this->as($this->principal)->putJson("/api/v1/calendar/{$shared['id']}", ['title' => 'Eid Milad un-Nabi (two days)'])
            ->assertOk()->assertJsonPath('data.campus_id', null)->assertJsonPath('data.is_campus_wide', true);

        // A campus editor cannot move an event onto a campus outside their assignment.
        $this->as($this->principal)->postJson('/api/v1/calendar', [
            'title' => 'Not ours', 'type' => 'event', 'start_date' => '2026-10-27', 'campus_id' => $this->isb,
        ])->assertForbidden();

        $this->as($this->principal)->deleteJson("/api/v1/calendar/{$gala['id']}")->assertOk();
        $this->as($this->principal)->getJson('/api/v1/calendar?from=2026-10-01&to=2026-10-31')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_today_is_recognised_as_a_holiday_only_for_the_active_campus(): void
    {
        // Islamabad shuts today; Lahore must not inherit that.
        $this->as($this->admin, $this->isb)->postJson('/api/v1/calendar', [
            'title' => 'Water tanker maintenance', 'type' => 'closure', 'start_date' => '2026-10-20', 'end_date' => '2026-10-22',
        ])->assertCreated();

        $this->as($this->principal)->getJson('/api/v1/calendar/summary')->assertOk()
            ->assertJsonPath('data.today', '2026-10-21')
            ->assertJsonPath('data.today_is_holiday', false)
            ->assertJsonPath('data.this_month', 0)
            ->assertJsonPath('data.holidays_this_month', 0)
            ->assertJsonCount(0, 'data.upcoming')
            ->assertJsonCount(0, 'data.today_events');

        $this->as($this->principal)->postJson('/api/v1/calendar', [
            'title' => 'Ashura', 'type' => 'holiday', 'start_date' => '2026-10-20', 'end_date' => '2026-10-21',
        ])->assertCreated();
        $this->as($this->principal)->postJson('/api/v1/calendar', [
            'title' => 'Mid-term examinations', 'type' => 'exam', 'start_date' => '2026-10-25', 'end_date' => '2026-10-28',
            'affects_attendance' => false,
        ])->assertCreated();

        $summary = $this->as($this->principal)->getJson('/api/v1/calendar/summary')->assertOk()->json('data');
        $this->assertTrue($summary['today_is_holiday']);
        $this->assertSame(2, $summary['this_month']);
        $this->assertSame(1, $summary['holidays_this_month']);
        $this->assertSame('Ashura', $summary['today_events'][0]['title']);
        $this->assertCount(2, $summary['upcoming']);
        $this->assertSame('Mid-term examinations', $summary['next_event']['title']);

        // Islamabad keeps its own closure and none of the Lahore rows.
        $this->as($this->admin, $this->isb)->getJson('/api/v1/calendar/summary')->assertOk()
            ->assertJsonPath('data.today_is_holiday', true)
            ->assertJsonPath('data.this_month', 1)
            ->assertJsonPath('data.today_events.0.title', 'Water tanker maintenance')
            ->assertJsonPath('data.today_events.0.is_campus_wide', false);
    }

    // --------------------------------------------------------------------- discipline

    public function test_a_suspension_case_has_to_state_its_length(): void
    {
        $this->as($this->principal)->postJson('/api/v1/discipline/cases', [
            'student_id' => $this->ali->id, 'incident_on' => '2026-10-18', 'category' => 'bullying',
            'severity' => 'major', 'description' => 'Threw a stool at a classmate.',
            'action' => 'suspension', 'suspension_days' => 0,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'A suspension must say how many days the student is suspended for.']);

        $this->as($this->principal)->postJson('/api/v1/discipline/cases', [
            'student_id' => $this->ali->id, 'incident_on' => '2026-10-18', 'category' => 'uniform',
            'severity' => 'minor', 'description' => 'Came without a blazer.',
            'action' => 'verbal_warning', 'suspension_days' => 5,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Suspension days only apply when the action is a suspension — set them to zero.']);

        $case = $this->as($this->principal)->postJson('/api/v1/discipline/cases', [
            'student_id' => $this->ali->id, 'incident_on' => '2026-10-18', 'category' => 'bullying',
            'severity' => 'major', 'description' => 'Threw a stool at a classmate.',
            'action' => 'suspension', 'suspension_days' => 3, 'parent_notified' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.handled_by', $this->principalId)
            ->assertJsonPath('data.suspension_days', 3)
            ->assertJsonPath('data.parent_notified', true)
            ->assertJsonPath('data.campus_id', $this->lhr)
            ->json('data');

        // Only an open case is editable, and the rule survives an edit.
        $this->as($this->principal)->putJson("/api/v1/discipline/cases/{$case['id']}", ['severity' => 'moderate'])
            ->assertOk()->assertJsonPath('data.severity', 'moderate')->assertJsonPath('data.suspension_days', 3);
        $this->as($this->principal)->putJson("/api/v1/discipline/cases/{$case['id']}", ['action' => 'detention'])
            ->assertOk()->assertJsonPath('data.action', 'detention')->assertJsonPath('data.suspension_days', 0);
        $this->as($this->principal)->putJson("/api/v1/discipline/cases/{$case['id']}", ['action' => 'suspension'])
            ->assertStatus(422);

        // Closing needs a real action and the remarks that explain it.
        $this->as($this->principal)->postJson("/api/v1/discipline/cases/{$case['id']}/close", ['action' => 'verbal_warning'])
            ->assertStatus(422)->assertJsonValidationErrors(['remarks']);
        $this->as($this->principal)->postJson("/api/v1/discipline/cases/{$case['id']}/close", [
            'action' => 'none', 'remarks' => 'Nothing was done.',
        ])->assertStatus(422)->assertJsonValidationErrors(['action']);

        $this->as($this->principal)->postJson("/api/v1/discipline/cases/{$case['id']}/close", [
            'action' => 'detention', 'remarks' => 'Two Saturday detentions, served with the class teacher.',
        ])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.handled_by', $this->principalId)
            ->assertJsonPath('data.remarks', 'Two Saturday detentions, served with the class teacher.');

        $twice = $this->as($this->principal)->postJson("/api/v1/discipline/cases/{$case['id']}/close", [
            'action' => 'detention', 'remarks' => 'Close it again?',
        ])->assertStatus(422);
        $this->assertStringContainsString('already closed', (string) $twice->json('message'));

        $this->as($this->principal)->putJson("/api/v1/discipline/cases/{$case['id']}", ['severity' => 'minor'])->assertStatus(422);
    }

    public function test_the_register_counts_open_cases_and_repeat_students(): void
    {
        $this->openCase($this->ali, ['incident_on' => '2026-10-02', 'category' => 'bullying', 'severity' => 'major']);
        $this->openCase($this->ali, ['incident_on' => '2026-10-09', 'category' => 'damage', 'severity' => 'major']);
        $this->openCase($this->ali, ['incident_on' => '2026-10-15', 'category' => 'uniform']);
        $punctuality = $this->openCase($this->gradeTen, ['category' => 'punctuality', 'severity' => 'moderate']);

        // A student from another campus cannot be charged from Lahore.
        $refused = $this->as($this->principal)->postJson('/api/v1/discipline/cases', [
            'student_id' => $this->isbStudent->id, 'incident_on' => '2026-10-18', 'category' => 'conduct',
            'severity' => 'minor', 'description' => 'A Lahore teacher cannot write up an Islamabad student.',
        ])->assertStatus(422);
        $this->assertStringContainsString('not in your campus', (string) $refused->json('message'));

        $summary = $this->as($this->principal)->getJson('/api/v1/discipline/summary')->assertOk()->json('data');
        $this->assertSame(4, $summary['open']);
        $this->assertSame(0, $summary['closed_this_month']);
        $this->assertSame(2, $summary['major_open']);
        $this->assertSame(['minor' => 1, 'moderate' => 1, 'major' => 2], $summary['by_severity']);
        $this->assertCount(2, $summary['top_students']);
        $this->assertSame(trim($this->ali->first_name . ' ' . $this->ali->last_name), $summary['top_students'][0]['name']);
        $this->assertSame($this->ali->admission_no, $summary['top_students'][0]['admission_no']);
        $this->assertSame(3, $summary['top_students'][0]['open_cases']);

        $this->as($this->principal)->postJson("/api/v1/discipline/cases/{$punctuality['id']}/close", [
            'action' => 'parent_called', 'remarks' => 'Guardian signed the register and changed the school van.',
        ])->assertOk();

        $after = $this->as($this->principal)->getJson('/api/v1/discipline/summary')->assertOk()->json('data');
        $this->assertSame(3, $after['open']);
        $this->assertSame(1, $after['closed_this_month']);
        // The closed case was a moderate one, so the two open major cases are untouched.
        $this->assertSame(2, $after['major_open']);
        $this->assertSame(['minor' => 1, 'moderate' => 0, 'major' => 2], $after['by_severity']);

        $cases = fn (string $query) => $this->as($this->principal)->getJson('/api/v1/discipline/cases?' . $query)
            ->assertOk()->json('data');

        $this->assertSame(4, $cases('')['total']);
        $this->assertSame(2, $cases('severity=major')['total']);
        $this->assertSame(1, $cases('status=closed')['total']);
        $this->assertSame(3, $cases('class_id=' . $this->ali->class_id)['total']);
        $this->assertSame(1, $cases('class_id=' . $this->gradeTen->class_id)['total']);
        $this->assertSame(1, $cases('category=punctuality')['total']);
        $this->assertSame(4, $cases('from=2026-10-01&to=2026-10-31')['total']);
        $this->assertSame(0, $cases('search=' . $this->gradeTen->admission_no . '&severity=major')['total']);
        $this->assertSame(1, $cases('search=' . $this->gradeTen->admission_no)['total']);
        $this->assertSame($this->gradeTen->id, $cases('student_id=' . $this->gradeTen->id)['data'][0]['student_id']);
        $this->assertSame('closed', $cases('status=closed')['data'][0]['status']);
    }

    // -------------------------------------------------------------------- permissions

    public function test_the_three_modules_run_on_their_own_permissions(): void
    {
        $this->as($this->accounts)->getJson('/api/v1/certificates')->assertStatus(403);
        $this->as($this->accounts)->getJson('/api/v1/certificates/summary')->assertStatus(403);
        $this->as($this->accounts)->getJson('/api/v1/calendar')->assertStatus(403);
        $this->as($this->accounts)->getJson('/api/v1/discipline/summary')->assertStatus(403);

        // The class teacher may write up a case and read the calendar, but sees no documents.
        $this->as($this->teacher)->getJson('/api/v1/certificates')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/calendar')->assertOk();
        $this->as($this->teacher)->postJson('/api/v1/calendar', [
            'title' => 'Teachers are not calendar editors', 'type' => 'meeting', 'start_date' => '2026-10-24',
        ])->assertStatus(403);
        $this->as($this->teacher)->postJson('/api/v1/discipline/cases', [
            'student_id' => $this->ali->id, 'incident_on' => '2026-10-20', 'category' => 'uniform',
            'severity' => 'minor', 'description' => 'No blazer again.',
        ])->assertCreated()->assertJsonPath('data.handled_by', User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk')->id);

        // The receptionist may read the register but raise nothing.
        $this->as($this->reception)->getJson('/api/v1/certificates')->assertOk()->assertJsonCount(0, 'data.data');
        $this->as($this->reception)->postJson('/api/v1/certificates', [
            'student_id' => $this->ali->id, 'type' => 'bonafide', 'purpose' => 'a bus concession',
        ])->assertStatus(403);

        // Cross-campus rows are neither readable nor decidable.
        $isbRow = $this->as($this->admin, $this->isb)->postJson('/api/v1/certificates', [
            'student_id' => $this->isbStudent->id, 'type' => 'bonafide', 'purpose' => 'a bus concession',
        ])->assertCreated()->json('data');
        $this->as($this->principal)->postJson("/api/v1/certificates/{$isbRow['id']}/issue")->assertNotFound();
        $this->as($this->principal)->getJson("/api/v1/certificates/{$isbRow['id']}/print")->assertNotFound();
        $this->as($this->principal)->getJson('/api/v1/certificates')->assertOk()->assertJsonPath('data.total', 0);
    }
}
