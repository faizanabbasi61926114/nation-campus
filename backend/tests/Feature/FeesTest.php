<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\FeeWaiver;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeesTest extends TestCase
{
    use RefreshDatabase;

    private string $accounts;   // accounts_officer, campus LHR
    private string $finance;    // finance_director, head office
    private string $admin;      // super admin
    private string $teacher;    // no fee permissions
    private int $lhr;
    private int $isb;
    private FeeStructure $tuition;
    private Student $a;
    private Student $b;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');
        $this->finance = $login('finance@sunrise.edu.pk');
        $this->admin = $login('admin@nationcampus.edu.pk');
        $this->teacher = $login('teacher.lhr@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;

        $this->tuition = FeeStructure::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'Monthly Tuition',
            'category' => 'tuition', 'amount' => 5000, 'gst_percent' => 0,
            'frequency' => 'monthly', 'due_day' => 10, 'is_active' => true,
        ]);

        $mk = fn (string $no, string $name) => Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'admission_no' => $no, 'first_name' => $name,
            'last_name' => 'K', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F',
            'guardian_phone' => '+92 300 1111111', 'status' => 'active',
        ]);
        $this->a = $mk('FE-1', 'Alpha');
        $this->b = $mk('FE-2', 'Bravo');
    }

    /** Sanctum caches the resolved user across in-process requests — reset before switching identity. */
    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    private function invoice(Student $student, array $attrs = []): FeeInvoice
    {
        return FeeInvoice::create(array_merge([
            'organization_id' => 1, 'campus_id' => $this->lhr,
            'student_id' => $student->id, 'fee_structure_id' => $this->tuition->id,
            'title' => 'Monthly Tuition', 'amount' => 5000, 'gst_amount' => 0,
            'discount' => 0, 'late_fee' => 0, 'status' => 'unpaid',
            'issue_date' => '2026-10-01', 'due_date' => '2026-10-15',
            'session_month' => '2026-10',
        ], $attrs));
    }

    public function test_generates_invoices_for_active_campus_students_and_is_idempotent(): void
    {
        $res = $this->as($this->accounts)->postJson('/api/v1/fee-invoices/generate', [
            'fee_structure_id' => $this->tuition->id, 'session_month' => '2026-11',
        ]);
        $res->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame(2, FeeInvoice::withoutGlobalScopes()->where('session_month', '2026-11')->count());
        $this->assertSame('2026-11-10', FeeInvoice::withoutGlobalScopes()->where('session_month', '2026-11')->first()->due_date->toDateString());

        // Re-running must not duplicate (unique student + structure + month).
        $this->as($this->accounts)->postJson('/api/v1/fee-invoices/generate', [
            'fee_structure_id' => $this->tuition->id, 'session_month' => '2026-11',
        ])->assertOk()->assertJsonPath('data.created', 0);
        $this->assertSame(2, FeeInvoice::withoutGlobalScopes()->where('session_month', '2026-11')->count());
    }

    public function test_generate_requires_a_campus_context(): void
    {
        $this->as($this->admin)->postJson('/api/v1/fee-invoices/generate', [
            'fee_structure_id' => $this->tuition->id, 'session_month' => '2026-11',
        ])->assertStatus(422);
    }

    public function test_fee_structure_can_be_updated(): void
    {
        $response = $this->as($this->accounts)->putJson("/api/v1/fee-structures/{$this->tuition->id}", [
            'name' => 'Annual Tuition',
            'category' => 'tuition',
            'amount' => 6200,
            'gst_percent' => 5,
            'frequency' => 'annual',
            'due_day' => 15,
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Annual Tuition')
            ->assertJsonPath('data.frequency', 'annual')
            ->assertJsonPath('data.is_active', false);
        $this->assertSame(6200.0, (float) $this->tuition->fresh()->amount);
    }

    public function test_payment_records_update_invoice_status_and_rejects_overpayment(): void
    {
        $invoice = $this->invoice($this->a);

        $this->as($this->accounts)->postJson("/api/v1/fee-invoices/{$invoice->id}/payments", [
            'amount' => 7000, 'channel' => 'cash',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Payment exceeds outstanding balance of Rs 5000.']);

        $first = $this->as($this->accounts)->postJson("/api/v1/fee-invoices/{$invoice->id}/payments", [
            'amount' => 2000, 'channel' => 'jazzcash', 'reference' => 'JC-991',
        ]);
        $first->assertCreated()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.balance', 3000);
        $this->assertStringStartsWith('RCP-LHR-', $first->json('data.payments.0.receipt_no'));

        $second = $this->as($this->accounts)->postJson("/api/v1/fee-invoices/{$invoice->id}/payments", [
            'amount' => 3000, 'channel' => 'bank_transfer',
        ]);
        $second->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', 0);

        $this->as($this->accounts)->postJson("/api/v1/fee-invoices/{$invoice->id}/payments", [
            'amount' => 100, 'channel' => 'cash',
        ])->assertStatus(422);

        // Reversing the bank transfer drops the invoice back to partial.
        $this->as($this->accounts)->postJson('/api/v1/fee-payments/' . $second->json('data.payments.1.id') . '/reverse', [
            'reason' => 'Cheque bounced',
        ])->assertOk();
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame(2000.0, $invoice->fresh()->paidAmount());
        $this->assertSame('reversed', $invoice->fresh()->payments()->latest('id')->value('status'));
    }

    public function test_late_fees_apply_only_to_uncovered_overdue_invoices(): void
    {
        $overdue = $this->invoice($this->a, ['due_date' => now()->subDays(5)->toDateString(), 'session_month' => '2026-09']);
        $waivered = $this->invoice($this->b, ['due_date' => now()->subDays(5)->toDateString(), 'session_month' => '2026-08']);
        FeeWaiver::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'student_id' => $this->b->id,
            'invoice_id' => $waivered->id, 'percent' => 25, 'reason' => 'Hardship', 'status' => 'pending',
        ]);

        $this->as($this->accounts)->postJson('/api/v1/fees/apply-late-fees')->assertOk();

        $this->assertSame(100.0, (float) $overdue->fresh()->late_fee); // 2% of Rs 5,000
        $this->assertSame(0.0, (float) $waivered->fresh()->late_fee);

        // Campus override wins over the organisation default.
        Setting::put(1, $this->lhr, 'fees.late_fee_percent', 5);
        $overdue->refresh()->update(['late_fee' => 0]);
        $this->as($this->accounts)->postJson('/api/v1/fees/apply-late-fees')->assertOk();
        $this->assertSame(250.0, (float) $overdue->fresh()->late_fee);
    }

    public function test_approved_waiver_applies_discount_and_full_waiver_marks_invoice_waived(): void
    {
        $invoice = $this->invoice($this->a);
        $waiver = FeeWaiver::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'student_id' => $this->a->id,
            'invoice_id' => $invoice->id, 'percent' => 100, 'reason' => 'Full scholarship', 'status' => 'pending',
            'requested_by' => \App\Models\User::firstWhere('email', 'accounts.lhr@sunrise.edu.pk')->id,
        ]);

        // Accounts officer may request but not approve.
        $this->as($this->accounts)->postJson("/api/v1/fee-waivers/{$waiver->id}/decide", ['decision' => 'approved'])
            ->assertStatus(403);

        $this->as($this->finance)->postJson("/api/v1/fee-waivers/{$waiver->id}/decide", ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $invoice->refresh();
        $this->assertSame(5000.0, (float) $invoice->discount);
        $this->assertSame('waived', $invoice->status);

        // A decided waiver cannot be re-decided.
        $this->as($this->finance)->postJson("/api/v1/fee-waivers/{$waiver->id}/decide", ['decision' => 'rejected'])
            ->assertStatus(422);
    }

    public function test_invoices_are_scoped_to_the_active_campus(): void
    {
        $this->invoice($this->a);
        $this->invoice($this->b, ['session_month' => '2026-09']);
        FeeInvoice::create([
            'organization_id' => 1, 'campus_id' => $this->isb, 'student_id' => $this->a->id,
            'fee_structure_id' => $this->tuition->id, 'title' => 'ISB Tuition', 'amount' => 8000,
            'gst_amount' => 0, 'discount' => 0, 'late_fee' => 0, 'status' => 'unpaid',
            'issue_date' => '2026-10-01', 'due_date' => '2026-10-20', 'session_month' => '2026-07',
        ]);

        $this->as($this->accounts)->getJson('/api/v1/fee-invoices')
            ->assertOk()->assertJsonCount(2, 'data.data');

        // Head office sees all campuses unless one is selected.
        $this->as($this->admin)->getJson('/api/v1/fee-invoices')
            ->assertOk()->assertJsonCount(3, 'data.data');
        $this->as($this->admin, $this->isb)->getJson('/api/v1/fee-invoices')
            ->assertOk()->assertJsonCount(1, 'data.data');

        // A campus the user does not belong to is rejected outright.
        $this->as($this->accounts, $this->isb)->getJson('/api/v1/fee-invoices')->assertStatus(403);

        $this->as($this->accounts)->getJson('/api/v1/fees/outstanding')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_waiver_cannot_reference_records_from_another_campus(): void
    {
        $isbStructure = FeeStructure::create([
            'organization_id' => 1, 'campus_id' => $this->isb, 'name' => 'ISB Tuition', 'category' => 'tuition',
            'amount' => 8000, 'gst_percent' => 0, 'frequency' => 'monthly', 'due_day' => 12,
        ]);
        $foreign = Student::create([
            'organization_id' => 1, 'campus_id' => $this->isb, 'admission_no' => 'FE-ISB-1', 'first_name' => 'Farah',
            'last_name' => 'K', 'gender' => 'female', 'dob' => '2012-01-01', 'father_name' => 'F', 'status' => 'active',
        ]);
        $foreignInvoice = FeeInvoice::create([
            'organization_id' => 1, 'campus_id' => $this->isb, 'student_id' => $foreign->id,
            'fee_structure_id' => $isbStructure->id, 'title' => 'ISB Tuition', 'amount' => 8000, 'gst_amount' => 0,
            'discount' => 0, 'late_fee' => 0, 'status' => 'unpaid',
            'issue_date' => '2026-10-01', 'due_date' => '2026-10-12', 'session_month' => '2026-10',
        ]);

        $this->as($this->accounts)->postJson('/api/v1/fee-waivers', [
            'student_id' => $foreign->id, 'percent' => 10, 'reason' => 'Cross tenant attempt',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Student does not exist in your campus.']);

        $this->as($this->accounts)->postJson('/api/v1/fee-waivers', [
            'student_id' => $this->a->id, 'invoice_id' => $foreignInvoice->id, 'percent' => 10, 'reason' => 'Cross tenant invoice',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Invoice does not exist in your campus.']);

        $this->as($this->accounts)->postJson('/api/v1/fee-waivers', [
            'student_id' => $this->a->id, 'percent' => 10, 'reason' => 'Legitimate hardship',
        ])->assertCreated();
    }

    public function test_fee_routes_require_fee_permissions(): void
    {
        $this->as($this->teacher)->getJson('/api/v1/fee-invoices')->assertStatus(403);
        $this->as($this->teacher)->postJson('/api/v1/fee-structures', [
            'name' => 'X', 'category' => 'tuition', 'amount' => 100, 'frequency' => 'monthly',
        ])->assertStatus(403);

        // Finance director cannot record payments (no fees.payments_record).
        $invoice = $this->invoice($this->a);
        $this->as($this->finance)->postJson("/api/v1/fee-invoices/{$invoice->id}/payments", [
            'amount' => 500, 'channel' => 'cash',
        ])->assertStatus(403);
    }

    public function test_summary_and_filters_on_invoice_ledger(): void
    {
        $paid = $this->invoice($this->a);
        $this->invoice($this->b, ['session_month' => '2026-09']);
        $this->as($this->accounts)->postJson("/api/v1/fee-invoices/{$paid->id}/payments", [
            'amount' => 5000, 'channel' => 'cheque',
        ])->assertCreated();

        $all = $this->as($this->accounts)->getJson('/api/v1/fee-invoices');
        $all->assertOk()->assertJsonPath('summary.billed', 10000)->assertJsonPath('summary.collected', 5000)
            ->assertJsonPath('summary.outstanding', 5000);

        $this->as($this->accounts)->getJson('/api/v1/fee-invoices?status=paid')
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.status', 'paid');
        $this->as($this->accounts)->getJson('/api/v1/fee-invoices?student_id=' . $this->b->id)
            ->assertOk()->assertJsonCount(1, 'data.data');

        $this->as($this->accounts)->getJson("/api/v1/fee-invoices/{$paid->id}")
            ->assertOk()->assertJsonPath('data.balance', 0)->assertJsonCount(1, 'data.payments');
    }
}
