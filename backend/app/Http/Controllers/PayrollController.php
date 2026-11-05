<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Setting;
use App\Models\Staff;
use App\Support\Ledger;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    public function __construct(private Ledger $ledger) {}

    public function runs(): JsonResponse
    {
        $runs = PayrollRun::with(['campus:id,name,code', 'preparer:id,name', 'approver:id,name'])
            ->withCount('payslips')
            ->orderByDesc('month')
            ->limit(60)
            ->get();
        return response()->json(['data' => $runs]);
    }

    public function storeRun(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before running payroll.'], 422);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'name' => 'nullable|string|max:120',
        ]);
        $exists = PayrollRun::where('campus_id', $ctx->campusId())->where('month', $data['month'])->exists();
        if ($exists) return response()->json(['message' => 'A payroll run already exists for ' . $data['month'] . '.'], 422);

        $run = PayrollRun::create([
            'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
            'month' => $data['month'], 'name' => $data['name'] ?? 'Monthly payroll — ' . $data['month'],
            'status' => 'draft', 'prepared_by' => $request->user()->id,
        ]);
        return response()->json(['data' => $run], 201);
    }

    /** Build (or rebuild) every payslip for the run from staff salary structures. */
    public function calculate(Request $request, PayrollRun $run): JsonResponse
    {
        if (in_array($run->status, ['approved', 'paid'], true)) {
            return response()->json(['message' => 'Payroll is ' . $run->status . ' and can no longer be recalculated.'], 422);
        }
        $ctx = app(TenantContext::class);
        $rules = $this->rules($ctx, $run->campus_id);

        $staff = Staff::where('campus_id', $run->campus_id)
            ->whereNotIn('status', ['resigned', 'terminated'])
            ->with('designation:id,name')
            ->orderBy('first_name')
            ->get();

        DB::transaction(function () use ($run, $staff, $rules, $request, $ctx) {
            $run->payslips()->delete();
            $gross = $deductions = $net = 0.0;

            foreach ($staff as $s) {
                $slip = $this->buildPayslip($run, $s, $rules);
                Payslip::create($slip + [
                    'organization_id' => $ctx->organizationId, 'campus_id' => $run->campus_id,
                    'payroll_run_id' => $run->id, 'staff_id' => $s->id, 'status' => 'pending',
                ]);
                $gross += $slip['gross'];
                $deductions += $slip['total_deductions'];
                $net += $slip['net_pay'];
            }

            $run->update([
                'status' => 'calculated',
                'total_gross' => round($gross, 2),
                'total_deductions' => round($deductions, 2),
                'total_net' => round($net, 2),
                'prepared_by' => $request->user()->id,
            ]);
        });

        return response()->json(['data' => $run->fresh()->load('campus:id,name,code'),
            'message' => 'Calculated ' . $run->payslips()->count() . ' payslip(s) for ' . $run->month . '.']);
    }

    public function transition(Request $request, PayrollRun $run): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:calculated,approved,paid']]);
        $allowed = PayrollRun::TRANSITIONS[$run->status] ?? [];
        if (!in_array($data['status'], $allowed, true)) {
            return response()->json(['message' => "Cannot move payroll from {$run->status} to {$data['status']}."], 422);
        }

        if ($data['status'] === 'approved' && $run->payslips()->count() === 0) {
            return response()->json(['message' => 'Calculate payslips before approving the run.'], 422);
        }

        $payload = ['status' => $data['status']];
        if ($data['status'] === 'approved') $payload['approved_by'] = $request->user()->id;
        if ($data['status'] === 'paid') {
            $payload['paid_on'] = now()->toDateString();
            $run->payslips()->update(['status' => 'paid']);
        }
        $run->update($payload);

        if ($data['status'] === 'paid' && (float) $run->total_net > 0) {
            $this->ledger->postPayrollRun($run->organization_id, $run->campus_id, $run->month, (float) $run->total_net, $request->user()->id);
        }

        return response()->json(['data' => $run->fresh(), 'message' => 'Payroll marked as ' . $data['status'] . '.']);
    }

    public function payslips(PayrollRun $run): JsonResponse
    {
        $run->loadMissing(['campus:id,name,code', 'preparer:id,name', 'approver:id,name']);
        $slips = Payslip::with(['staff:id,first_name,last_name,employee_no,designation_id', 'staff.designation:id,name'])
            ->where('payroll_run_id', $run->id)
            ->orderBy('net_pay', 'desc')
            ->get();
        return response()->json(['data' => ['run' => $run, 'payslips' => $slips]]);
    }

    public function showPayslip(Payslip $payslip): JsonResponse
    {
        return response()->json(['data' => $payslip->load(['staff.department:id,name', 'run'])]);
    }

    public function staffOptions(): JsonResponse
    {
        return response()->json(['data' => Staff::whereNotIn('status', ['resigned', 'terminated'])
            ->with('designation:id,name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_no', 'base_salary', 'designation_id'])]);
    }

    /**
     * Pakistan payroll conventions, all campus-overridable through settings:
     * EOBI is a percentage of gross wages up to a monthly ceiling; income tax is a flat
     * percentage on the amount the annual gross earns above the tax-free threshold.
     */
    private function rules(TenantContext $ctx, ?int $campusId): array
    {
        $org = $ctx->organizationId ?: 1;
        $get = fn (string $key, float $default) => (float) (Setting::getFor($org, $campusId, $key) ?? $default);
        return [
            'eobi_percent' => $get('payroll.eobi_percent', 2),
            'eobi_ceiling' => $get('payroll.eobi_wage_ceiling', 60000),
            'tax_threshold' => $get('payroll.tax_free_threshold', 600000),
            'tax_percent' => $get('payroll.tax_percent', 10),
            'working_days' => max(1, $get('payroll.working_days', 26)),
        ];
    }

    private function buildPayslip(PayrollRun $run, Staff $s, array $rules): array
    {
        $basic = (float) $s->base_salary;
        $houseRent = round($basic * (float) $s->house_rent_percent / 100, 2);
        $medical = (float) $s->medical_allowance;
        $gross = round($basic + $houseRent + $medical, 2);

        $unpaidDays = $this->unpaidLeaveDays($s, $run->month);
        $leaveDeduction = round($gross / $rules['working_days'] * $unpaidDays, 2);
        $pensionable = round(min($gross, $rules['eobi_ceiling']) * $rules['eobi_percent'] / 100, 2);
        $annualised = $gross * 12;
        $tax = $annualised <= $rules['tax_threshold']
            ? 0.0
            : round(($annualised - $rules['tax_threshold']) * $rules['tax_percent'] / 100 / 12, 2);
        $other = (float) $s->other_deductions;

        $totalDeductions = round($pensionable + $tax + $other + $leaveDeduction, 2);

        return [
            'basic' => $basic, 'house_rent' => $houseRent, 'medical' => $medical, 'other_earnings' => 0,
            'gross' => $gross, 'eobi' => $pensionable, 'income_tax' => $tax,
            'other_deductions' => round($other + $leaveDeduction, 2),
            'total_deductions' => $totalDeductions,
            'net_pay' => round($gross - $totalDeductions, 2),
            'unpaid_leave_days' => $unpaidDays,
            'remarks' => $leaveDeduction > 0 ? "Unpaid leave deduction: {$unpaidDays} day(s)" : null,
        ];
    }

    private function unpaidLeaveDays(Staff $s, string $month): int
    {
        [$y, $m] = explode('-', $month);
        $start = Carbon::create($y, $m, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        return (int) $s->leaves()
            ->where('status', 'approved')
            ->where('type', 'unpaid')
            ->whereDate('from_date', '<=', $end->toDateString())
            ->whereDate('to_date', '>=', $start->toDateString())
            ->sum('days');
    }
}
