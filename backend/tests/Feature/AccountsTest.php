<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Campus;
use App\Models\FeeInvoice;
use App\Models\FeeStructure;
use App\Models\JournalLine;
use App\Models\JournalVoucher;
use App\Models\PayrollRun;
use App\Models\Staff;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private string $accounts; // accounts_officer, campus LHR
    private string $finance;  // finance_director, head office
    private string $hr;
    private string $teacher;
    private int $lhr;
    private int $isb;

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
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');
        $this->finance = $login('finance@sunrise.edu.pk');
        $this->hr = $login('hr.lhr@sunrise.edu.pk');
        $this->teacher = $login('teacher.lhr@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;

        $this->seed(\Database\Seeders\AccountsSeeder::class);
    }

    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    private function account(string $code): Account
    {
        return Account::withoutGlobalScopes()->firstWhere('code', $code);
    }

    public function test_the_chart_of_accounts_is_installed_and_reports_opening_balances(): void
    {
        $this->assertCount(32, Account::withoutGlobalScopes()->get());

        $chart = $this->as($this->accounts)->getJson('/api/v1/accounts/chart')->assertOk()->json('data');
        $byCode = collect($chart)->keyBy('code');
        $this->assertSame(1607600, $byCode->get('1010')['balance']); // opening bank less LHR payments, plus donation
        $this->assertSame(5300000, $byCode->get('3000')['balance']); // opening fund, credit normal
        $this->assertSame(700000, $byCode->get('5100')['balance']);  // two LHR rents; ISB rent is excluded

        // Head office sees the consolidated chart including the Islamabad rent.
        $consolidated = $this->as($this->finance)->getJson('/api/v1/accounts/chart')->assertOk()->json('data');
        $this->assertSame(980000, collect($consolidated)->keyBy('code')->get('5100')['balance']);

        $this->as($this->accounts)->getJson('/api/v1/accounts/summary')->assertOk()
            ->assertJsonPath('data.vouchers', 11)
            ->assertJsonStructure(['data' => ['cash_on_hand', 'income_this_month', 'expense_this_month', 'net_this_month']]);
    }

    public function test_a_voucher_must_balance_before_it_posts(): void
    {
        $this->as($this->accounts)->postJson('/api/v1/vouchers', [
            'voucher_date' => '2026-10-18', 'type' => 'payment', 'narrative' => 'Unbalanced trial',
            'lines' => [
                ['account_id' => $this->account('5300')->id, 'debit' => 5000],
                ['account_id' => $this->account('1000')->id, 'credit' => 4000],
            ],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Voucher is out of balance: debits Rs 5000 vs credits Rs 4000.']);

        $this->as($this->accounts)->postJson('/api/v1/vouchers', [
            'voucher_date' => '2026-10-18', 'type' => 'payment', 'narrative' => 'Library books',
            'lines' => [
                ['account_id' => $this->account('5300')->id, 'debit' => 12000, 'memo' => 'Books'],
                ['account_id' => $this->account('1000')->id, 'credit' => 12000],
            ],
        ])->assertCreated()->assertJsonPath('data.voucher_no', 'VCH-202610-LHR-00010')
            ->assertJsonPath('data.amount', '12000.00')->assertJsonCount(2, 'data.lines');

        $this->assertSame(2, JournalLine::where('voucher_id', JournalVoucher::firstWhere('voucher_no', 'VCH-202610-LHR-00010')->id)->count());

        // A second voucher continues the campus sequence.
        $this->as($this->accounts)->postJson('/api/v1/vouchers', [
            'voucher_date' => '2026-10-19', 'type' => 'contra', 'narrative' => 'Bank to safe',
            'lines' => [
                ['account_id' => $this->account('1000')->id, 'debit' => 50000],
                ['account_id' => $this->account('1010')->id, 'credit' => 50000],
            ],
        ])->assertCreated()->assertJsonPath('data.voucher_no', 'VCH-202610-LHR-00011');

        // The April opening voucher is numbered from its own period, not from today.
        $this->assertSame('VCH-202604-LHR-00001', JournalVoucher::withoutGlobalScopes()->firstWhere('source_ref', 'opening-2026')->voucher_no);
    }

    public function test_vouchers_reject_foreign_accounts_and_future_dates(): void
    {
        // A campus-owned account is invisible to another campus.
        $isbOnly = Account::create([
            'organization_id' => 1, 'campus_id' => $this->isb,
            'code' => '1090', 'name' => 'ISB Imprest Float', 'type' => 'asset',
        ]);

        $this->as($this->accounts)->postJson('/api/v1/vouchers', [
            'voucher_date' => '2026-10-18', 'type' => 'payment', 'narrative' => 'Cross-campus attempt',
            'lines' => [
                ['account_id' => $isbOnly->id, 'debit' => 100],
                ['account_id' => $this->account('1000')->id, 'credit' => 100],
            ],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Account ' . $isbOnly->id . ' is not available in your campus.']);

        $this->as($this->accounts)->postJson('/api/v1/vouchers', [
            'voucher_date' => '2026-12-31', 'type' => 'payment', 'narrative' => 'Future dated',
            'lines' => [
                ['account_id' => $this->account('5300')->id, 'debit' => 100],
                ['account_id' => $this->account('1000')->id, 'credit' => 100],
            ],
        ])->assertStatus(422);

        // Head office cannot post without picking a campus.
        $this->as($this->finance)->postJson('/api/v1/vouchers', [
            'voucher_date' => '2026-10-18', 'type' => 'journal', 'narrative' => 'No campus',
            'lines' => [
                ['account_id' => $this->account('5300')->id, 'debit' => 100],
                ['account_id' => $this->account('1000')->id, 'credit' => 100],
            ],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Select a campus before posting a voucher.']);
    }

    public function test_trial_balance_profit_loss_and_balance_sheet_all_agree(): void
    {
        $this->as($this->accounts)->postJson('/api/v1/vouchers', [
            'voucher_date' => '2026-10-18', 'type' => 'receipt', 'narrative' => 'Donation received',
            'lines' => [
                ['account_id' => $this->account('1010')->id, 'debit' => 200000],
                ['account_id' => $this->account('4500')->id, 'credit' => 200000],
            ],
        ])->assertCreated();

        $trial = $this->as($this->accounts)->getJson('/api/v1/reports/trial-balance')->assertOk()->json('data');
        $this->assertSame($trial['totals']['debit'], $trial['totals']['credit']);
        $this->assertSame(5650000, $trial['totals']['debit']); // opening fund plus every LHR movement

        $pl = $this->as($this->accounts)->getJson('/api/v1/reports/profit-loss?from=2026-04-01&to=2026-10-19')
            ->assertOk()->json('data');
        $this->assertSame(350000, $pl['total_income']);    // seeded donation plus the one posted above
        $this->assertSame(1161850, $pl['total_expense']);  // LHR only: ISB rent is not in this campus
        $this->assertSame(-811850, $pl['net']);

        $sheet = $this->as($this->accounts)->getJson('/api/v1/reports/balance-sheet')->assertOk()->json('data');
        $this->assertTrue($sheet['balanced']);
        $this->assertSame(4488150, $sheet['total_assets']);
        $this->assertSame($pl['net'], $sheet['current_surplus']);
        $this->assertSame($sheet['total_assets'], $sheet['total_liabilities'] + $sheet['total_equity'] + $sheet['current_surplus']);
    }

    public function test_fee_payments_post_receipt_vouchers_and_reversals_void_them(): void
    {
        $structure = FeeStructure::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'Monthly Tuition',
            'category' => 'tuition', 'amount' => 5000, 'frequency' => 'monthly', 'due_day' => 10, 'is_active' => true,
        ]);
        $student = Student::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'admission_no' => 'ACC-1', 'first_name' => 'Alpha',
            'last_name' => 'K', 'gender' => 'male', 'dob' => '2012-01-01', 'father_name' => 'F',
            'guardian_phone' => '+92 300 1111111', 'status' => 'active',
        ]);
        $invoice = FeeInvoice::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'student_id' => $student->id,
            'fee_structure_id' => $structure->id, 'title' => 'Monthly Tuition Fee', 'amount' => 5000,
            'gst_amount' => 0, 'discount' => 0, 'late_fee' => 0, 'status' => 'unpaid',
            'issue_date' => '2026-10-01', 'due_date' => '2026-10-10', 'session_month' => '2026-10',
        ]);

        $this->as($this->accounts)->postJson("/api/v1/fee-invoices/{$invoice->id}/payments", [
            'amount' => 5000, 'channel' => 'cash', 'paid_on' => '2026-10-18',
        ])->assertCreated();

        $payment = $invoice->payments()->first();
        $voucher = JournalVoucher::firstWhere('source', 'fee_payment');
        $this->assertNotNull($voucher);
        $this->assertSame('receipt', $voucher->type);
        $this->assertSame($payment->receipt_no, $voucher->source_ref);
        $lines = $voucher->load('lines')->lines->keyBy('account_id');
        $this->assertSame('5000.00', $lines[$this->account('1000')->id]->debit);
        $this->assertSame('5000.00', $lines[$this->account('4000')->id]->credit);

        $this->as($this->accounts)->postJson("/api/v1/fee-payments/{$payment->id}/reverse", ['reason' => 'Cheque bounced'])
            ->assertOk()->assertJsonPath('message', 'Payment reversed.');
        $this->assertSame('reversed', $voucher->fresh()->status);

        // Reversed vouchers drop out of the ledger completely.
        $cash = collect($this->as($this->accounts)->getJson('/api/v1/accounts/chart')->assertOk()->json('data'))
            ->firstWhere('code', '1000');
        $this->assertSame(680550, $cash['balance']);
    }

    public function test_paid_payroll_posts_the_salary_voucher(): void
    {
        Staff::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'employee_no' => 'ACC-S1',
            'first_name' => 'Usman', 'last_name' => 'R', 'gender' => 'male', 'joined_on' => '2021-04-01',
            'base_salary' => 100000, 'house_rent_percent' => 30, 'medical_allowance' => 0, 'status' => 'active',
        ]);
        $run = PayrollRun::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'month' => '2026-10',
            'name' => 'Payroll 2026-10', 'status' => 'draft',
        ]);

        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")->assertOk();
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'approved'])->assertOk();
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'paid'])->assertOk();

        $voucher = JournalVoucher::firstWhere('source', 'payroll');
        $this->assertNotNull($voucher);
        $this->assertSame('payment', $voucher->type);
        $this->assertSame('120800.00', $voucher->amount); // 130,000 gross less EOBI 1,200 and tax 8,000
        $lines = $voucher->load('lines')->lines->keyBy('account_id');
        $this->assertSame('120800.00', $lines[$this->account('5000')->id]->debit);
        $this->assertSame('120800.00', $lines[$this->account('1010')->id]->credit);
    }

    public function test_vouchers_are_campus_scoped_and_reversible_only_once(): void
    {
        $isbVoucher = JournalVoucher::withoutGlobalScopes()->firstWhere('campus_id', $this->isb);

        $this->as($this->accounts)->getJson('/api/v1/vouchers')->assertOk()
            ->assertJsonPath('data.total', 11);
        $this->as($this->finance)->getJson('/api/v1/vouchers')->assertOk()->assertJsonPath('data.total', 12);

        $this->as($this->accounts)->postJson("/api/v1/vouchers/{$isbVoucher->id}/reverse", ['reason' => 'Not ours'])
            ->assertNotFound();

        $lhrVoucher = JournalVoucher::withoutGlobalScopes()->firstWhere('campus_id', $this->lhr);
        $this->as($this->accounts)->postJson("/api/v1/vouchers/{$lhrVoucher->id}/reverse", ['reason' => 'Wrong head'])->assertOk()
            ->assertJsonPath('data.status', 'reversed');
        $this->as($this->accounts)->postJson("/api/v1/vouchers/{$lhrVoucher->id}/reverse", ['reason' => 'Again'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Voucher is already reversed.']);
    }

    public function test_account_ledger_shows_a_running_balance(): void
    {
        $rent = $this->account('5100');
        $ledger = $this->as($this->accounts)->getJson("/api/v1/accounts/{$rent->id}/ledger")->assertOk()->json('data');
        $this->assertCount(2, $ledger['lines']);
        $this->assertSame(350000, $ledger['lines'][0]['balance']);
        $this->assertSame(700000, $ledger['lines'][1]['balance']);
        $this->assertSame(700000, $ledger['totals']['debit']);
        $this->assertSame(0, $ledger['totals']['credit']);
    }

    public function test_accounts_routes_require_accounts_permissions(): void
    {
        $this->as($this->teacher)->getJson('/api/v1/accounts/chart')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/vouchers')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/reports/trial-balance')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/reports/profit-loss')->assertStatus(403);
        $this->as($this->teacher)->getJson('/api/v1/reports/balance-sheet')->assertStatus(403);

        $this->as($this->hr)->getJson('/api/v1/accounts/chart')->assertStatus(403);
    }

    public function test_new_accounts_can_be_added_and_deactivated(): void
    {
        $this->as($this->accounts)->postJson('/api/v1/accounts/chart', [
            'code' => '5300', 'name' => 'Duplicate', 'type' => 'expense',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Account code 5300 is already used.']);

        $this->as($this->accounts)->postJson('/api/v1/accounts/chart', [
            'code' => '5310', 'name' => 'Science Lab Consumables', 'type' => 'expense',
        ])->assertCreated()->assertJsonPath('data.organization_id', 1);

        $account = $this->account('5310');
        $this->as($this->accounts)->putJson("/api/v1/accounts/{$account->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
    }
}
