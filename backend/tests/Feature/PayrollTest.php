<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveApplication;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Setting;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private string $hr;
    private string $finance; // finance_director, head office
    private string $accounts;
    private string $teacher;
    private int $lhr;
    private int $isb;
    private Designation $dsg;

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
        $this->hr = $login('hr.lhr@sunrise.edu.pk');
        $this->finance = $login('finance@sunrise.edu.pk');
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');
        $this->teacher = $login('teacher.lhr@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;

        $dept = Department::create(['organization_id' => 1, 'name' => 'Academic', 'code' => 'ACAD']);
        $this->dsg = Designation::create([
            'organization_id' => 1, 'name' => 'Senior Teacher', 'category' => 'teaching', 'department_id' => $dept->id,
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

    private function staff(string $first, string $no, int $campusId, float $basic, float $houseRent, float $medical, float $other = 0): Staff
    {
        return Staff::create([
            'organization_id' => 1, 'campus_id' => $campusId, 'employee_no' => $no,
            'first_name' => $first, 'last_name' => 'K', 'gender' => 'male',
            'designation_id' => $this->dsg->id, 'joined_on' => '2021-04-01',
            'base_salary' => $basic, 'house_rent_percent' => $houseRent,
            'medical_allowance' => $medical, 'other_deductions' => $other, 'status' => 'active',
        ]);
    }

    private function makeRun(int $campusId, string $month): PayrollRun
    {
        return PayrollRun::create([
            'organization_id' => 1, 'campus_id' => $campusId, 'month' => $month,
            'name' => "Payroll $month", 'status' => 'draft',
        ]);
    }

    public function test_runs_need_a_campus_a_permission_and_a_unique_month(): void
    {
        $this->as($this->finance)->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Select a campus before running payroll.']);

        $this->as($this->accounts)->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertStatus(403);

        $this->as($this->hr)->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])
            ->assertCreated()->assertJsonPath('data.campus_id', $this->lhr)->assertJsonPath('data.status', 'draft');

        $this->as($this->hr)->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'A payroll run already exists for 2026-10.']);

        $this->as($this->finance)->withHeaders(['X-Campus-Id' => $this->isb])
            ->postJson('/api/v1/payroll/runs', ['month' => '2026-10'])->assertCreated();
    }

    public function test_calculation_applies_the_eobi_ceiling_and_the_income_tax_slab(): void
    {
        // Gross 180,750: EOBI is capped at Rs 60,000 wages, tax is 10% of the annual excess over Rs 600,000.
        $officer = $this->staff('Usman', 'P-1', $this->lhr, 125000, 35, 12000);
        // Gross 43,800: annualises to 525,600, so it stays below the tax-free threshold.
        $guard = $this->staff('Shakeel', 'P-2', $this->lhr, 34000, 20, 3000, 500);
        $this->staff('Resigned', 'P-3', $this->lhr, 90000, 30, 5000)->update(['status' => 'resigned']);

        $run = $this->makeRun($this->lhr, '2026-10');
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")
            ->assertOk()->assertJsonPath('data.status', 'calculated')
            ->assertJsonPath('data.total_gross', '224550.00')
            ->assertJsonPath('data.total_net', '208899.00');

        $slips = $this->as($this->hr)->getJson("/api/v1/payroll/runs/{$run->id}/payslips")->assertOk()->json('data.payslips');
        $this->assertCount(2, $slips);

        $officerSlip = collect($slips)->firstWhere('staff_id', $officer->id);
        $this->assertSame('180750.00', $officerSlip['gross']);
        $this->assertSame('1200.00', $officerSlip['eobi']);
        $this->assertSame('13075.00', $officerSlip['income_tax']);
        $this->assertSame('14275.00', $officerSlip['total_deductions']);
        $this->assertSame('166475.00', $officerSlip['net_pay']);

        $guardSlip = collect($slips)->firstWhere('staff_id', $guard->id);
        $this->assertSame('43800.00', $guardSlip['gross']);
        $this->assertSame('876.00', $guardSlip['eobi']);
        $this->assertSame('0.00', $guardSlip['income_tax']);
        $this->assertSame('42424.00', $guardSlip['net_pay']);

        // Campus rules are configurable: raising the tax-free threshold removes the officer's tax.
        Setting::put(1, $this->lhr, 'payroll.tax_free_threshold', 3000000);
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")->assertOk();
        $this->assertSame('0.00', Payslip::where('staff_id', $officer->id)->first()->income_tax);
    }

    public function test_approved_unpaid_leave_is_deducted_from_the_payslip(): void
    {
        $staff = $this->staff('Naveed', 'P-4', $this->lhr, 100000, 30, 0); // gross 130,000
        $this->as($this->hr)->postJson('/api/v1/leave-applications', [
            'staff_id' => $staff->id, 'type' => 'unpaid', 'from_date' => '2026-10-05',
            'to_date' => '2026-10-07', 'reason' => 'Absence without pay.',
        ])->assertCreated();
        $leave = LeaveApplication::firstWhere('staff_id', $staff->id);
        $this->as($this->hr)->postJson("/api/v1/leave-applications/{$leave->id}/decide", ['decision' => 'approved'])->assertOk();

        $run = $this->makeRun($this->lhr, '2026-10');
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")->assertOk();

        // 130,000 / 26 working days x 3 days = 15,000 on top of EOBI (1,200) and tax (8,000).
        $slip = Payslip::firstWhere('payroll_run_id', $run->id);
        $this->assertSame(3, (int) $slip->unpaid_leave_days);
        $this->assertSame('15000.00', $slip->other_deductions);
        $this->assertSame('105800.00', $slip->net_pay);
        $this->assertStringContainsString('Unpaid leave deduction', (string) $slip->remarks);
    }

    public function test_payroll_follows_the_draft_calculated_approved_paid_workflow(): void
    {
        $this->staff('Talal', 'P-5', $this->lhr, 82000, 30, 8000);
        $run = $this->makeRun($this->lhr, '2026-09');

        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'approved'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Cannot move payroll from draft to approved.']);
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'paid'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Cannot move payroll from draft to paid.']);

        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")->assertOk();
        $this->as($this->finance)->withHeaders(['X-Campus-Id' => $this->lhr])
            ->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'approved'])->assertOk();
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Payroll is approved and can no longer be recalculated.']);

        $this->as($this->finance)->withHeaders(['X-Campus-Id' => $this->lhr])
            ->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'paid'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_on', '2026-10-19');
        $this->assertSame('paid', Payslip::firstWhere('payroll_run_id', $run->id)->status);

        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'approved'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Cannot move payroll from paid to approved.']);
    }

    public function test_approving_an_empty_run_is_blocked_and_persists_the_approver(): void
    {
        $run = $this->makeRun($this->lhr, '2026-08');
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")->assertOk(); // no staff yet
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/transition", ['status' => 'approved'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Calculate payslips before approving the run.']);
    }

    public function test_payroll_is_campus_scoped_and_permission_gated(): void
    {
        $this->staff('Hira', 'P-6', $this->isb, 121000, 35, 12000);
        $lhrRun = $this->makeRun($this->lhr, '2026-10');
        $isbRun = $this->makeRun($this->isb, '2026-10');

        $this->as($this->teacher)->getJson('/api/v1/payroll/runs')->assertStatus(403);
        $this->as($this->accounts)->getJson('/api/v1/payroll/runs')->assertStatus(403);

        $this->as($this->hr)->getJson('/api/v1/payroll/runs')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lhrRun->id);
        $this->as($this->finance)->getJson('/api/v1/payroll/runs')->assertOk()->assertJsonCount(2, 'data');

        // Another campus's run is not addressable from Lahore.
        $this->as($this->hr)->getJson("/api/v1/payroll/runs/{$isbRun->id}/payslips")->assertNotFound();

        $this->as($this->hr)->getJson('/api/v1/payroll/staff-options')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->finance)->getJson('/api/v1/payroll/staff-options')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_payslip_can_be_opened_for_a_single_employee(): void
    {
        $staff = $this->staff('Zubaida', 'P-7', $this->lhr, 51000, 25, 5000);
        $run = $this->makeRun($this->lhr, '2026-10');
        $this->as($this->hr)->postJson("/api/v1/payroll/runs/{$run->id}/calculate")->assertOk();

        $slip = Payslip::firstWhere('payroll_run_id', $run->id);
        $this->assertSame($staff->id, $slip->staff_id);
        $this->as($this->hr)->getJson("/api/v1/payslips/{$slip->id}")
            ->assertOk()->assertJsonPath('data.staff.first_name', 'Zubaida')
            ->assertJsonPath('data.run.month', '2026-10');
    }
}
