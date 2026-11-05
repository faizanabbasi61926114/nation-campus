<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveApplication;
use App\Models\Setting;
use App\Models\Staff;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrController extends Controller
{
    public function departments(): JsonResponse
    {
        return response()->json(['data' => Department::with('head:id,name')->withCount('staff')->orderBy('name')->get()]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:20',
            'campus_id' => 'nullable|integer',
            'head_user_id' => 'nullable|exists:users,id',
        ]);
        $dept = Department::create($data + [
            'organization_id' => $ctx->organizationId,
            'campus_id' => $data['campus_id'] ?? $ctx->campusId(),
        ]);
        return response()->json(['data' => $dept], 201);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $department->update($request->validate([
            'name' => 'sometimes|required|string|max:120',
            'code' => 'sometimes|required|string|max:20',
            'head_user_id' => 'nullable|exists:users,id',
            'is_active' => 'sometimes|boolean',
        ]));
        return response()->json(['data' => $department->fresh()]);
    }

    public function designations(): JsonResponse
    {
        return response()->json(['data' => Designation::with('department:id,name')->orderBy('name')->get()]);
    }

    public function storeDesignation(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => ['required', 'in:teaching,administrative,support,senior'],
            'grade' => 'nullable|string|max:20',
            'department_id' => 'nullable|exists:departments,id',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0|gte:min_salary',
        ]);
        $d = Designation::create($data + ['organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId()]);
        return response()->json(['data' => $d->load('department:id,name')], 201);
    }

    public function updateDesignation(Request $request, Designation $designation): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'category' => ['sometimes', 'required', 'in:teaching,administrative,support,senior'],
            'grade' => 'sometimes|nullable|string|max:20',
            'department_id' => 'sometimes|nullable|exists:departments,id',
            'min_salary' => 'sometimes|nullable|numeric|min:0',
            'max_salary' => 'sometimes|nullable|numeric|min:0',
        ]);
        $min = $data['min_salary'] ?? $designation->min_salary;
        $max = $data['max_salary'] ?? $designation->max_salary;
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            throw ValidationException::withMessages(['max_salary' => 'The maximum salary must be greater than or equal to the minimum salary.']);
        }
        $designation->update($data);
        return response()->json(['data' => $designation->fresh('department:id,name')]);
    }

    public function staff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:active,probation,resigned,terminated',
            'department_id' => 'nullable|integer',
            'search' => 'nullable|string|max:60',
        ]);
        $staff = Staff::with(['department:id,name', 'designation:id,name,category', 'user:id,name,email'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['department_id'] ?? null, fn ($q, $d) => $q->where('department_id', $d))
            ->when($data['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")->orWhere('employee_no', 'like', "%$s%"));
            })
            ->orderBy('first_name')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        $staff->getCollection()->transform(function ($s) {
            $s->append('full_name');
            $s->monthly_gross = $s->grossSalary();
            $s->setVisible(['id', 'employee_no', 'first_name', 'last_name', 'full_name', 'gender', 'email', 'phone', 'joined_on',
                'base_salary', 'monthly_gross', 'status', 'department_id', 'designation_id', 'user_id', 'department', 'designation', 'user']);
            return $s;
        });

        return response()->json(['data' => $staff]);
    }

    public function showStaff(Staff $staff): JsonResponse
    {
        $staff->load(['department:id,name', 'designation:id,name,category,grade']);
        $staff->monthly_gross = $staff->grossSalary();
        $staff->leave_summary = $this->leaveSummary($staff);
        $staff->recent_leaves = $staff->leaves()->latest('from_date')->limit(10)->get(['id', 'staff_id', 'type', 'from_date', 'to_date', 'days', 'status', 'reason']);
        return response()->json(['data' => $staff]);
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before adding staff.'], 422);

        $data = $request->validate([
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'gender' => ['required', 'in:male,female,other'],
            'employee_no' => 'nullable|string|max:30',
            'cnic' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:30',
            'designation_id' => 'nullable|exists:designations,id',
            'department_id' => 'nullable|exists:departments,id',
            'joined_on' => 'required|date',
            'base_salary' => 'required|numeric|min:0',
            'house_rent_percent' => 'nullable|numeric|min:0|max:100',
            'medical_allowance' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'bank_account' => 'nullable|string|max:40',
            'status' => ['nullable', 'in:active,probation,resigned,terminated'],
            'user_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $code = optional($ctx->campus)->code ?? 'HO';
        $data['employee_no'] = $data['employee_no'] ?? $this->nextEmployeeNo($code);

        $staff = Staff::create($data + ['organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId()]);
        return response()->json(['data' => $staff->load(['department:id,name', 'designation:id,name'])], 201);
    }

    public function updateStaff(Request $request, Staff $staff): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:80',
            'last_name' => 'sometimes|required|string|max:80',
            'gender' => ['sometimes', 'required', 'in:male,female,other'],
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:30',
            'cnic' => 'nullable|string|max:20',
            'joined_on' => 'sometimes|required|date',
            'designation_id' => 'nullable|exists:designations,id',
            'department_id' => 'nullable|exists:departments,id',
            'base_salary' => 'sometimes|required|numeric|min:0',
            'house_rent_percent' => 'nullable|numeric|min:0|max:100',
            'medical_allowance' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'status' => ['sometimes|required', 'in:active,probation,resigned,terminated'],
            'resigned_on' => 'nullable|date',
            'confirmed_on' => 'nullable|date',
            'medical_allowance' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'bank_account' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:1000',
        ]);
        $staff->update($data);
        return response()->json(['data' => $staff->fresh(['department:id,name', 'designation:id,name'])]);
    }

    public function leaves(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,cancelled',
            'staff_id' => 'nullable|integer',
            'month' => 'nullable|date_format:Y-m',
        ]);
        $leaves = LeaveApplication::with(['staff:id,first_name,last_name,employee_no,department_id', 'decider:id,name'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['staff_id'] ?? null, fn ($q, $i) => $q->where('staff_id', $i))
            ->when($data['month'] ?? null, fn ($q, $m) => $q->whereDate('from_date', '>=', "$m-01")->whereDate('to_date', '<=', "$m-31"))
            ->latest('from_date')
            ->limit(200)
            ->get();
        return response()->json(['data' => $leaves]);
    }

    public function applyLeave(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'staff_id' => 'required|integer',
            'type' => ['required', 'in:' . implode(',', Staff::LEAVE_TYPES)],
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'reason' => 'required|string|max:255',
        ]);

        $staff = Staff::find($data['staff_id']);
        if (!$staff) return response()->json(['message' => 'Staff member not found in your campus.'], 422);
        if (in_array($staff->status, ['resigned', 'terminated'], true)) {
            return response()->json(['message' => 'Staff member is no longer in service.'], 422);
        }

        $from = Carbon::parse($data['from_date']);
        $to = Carbon::parse($data['to_date']);
        if ($from->gt(now()->addDays(90))) {
            return response()->json(['message' => 'Leave cannot be applied more than 90 days in advance.'], 422);
        }
        $days = $this->countableDays($from, $to, $ctx);

        $overlap = LeaveApplication::where('staff_id', $staff->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->exists();
        if ($overlap) return response()->json(['message' => 'This staff member already has leave in that period.'], 422);

        $leave = LeaveApplication::create($data + [
            'organization_id' => $ctx->organizationId, 'campus_id' => $staff->campus_id,
            'days' => $days, 'status' => 'pending',
        ]);
        return response()->json(['data' => $leave->load('staff:id,first_name,last_name,employee_no')], 201);
    }

    public function decideLeave(Request $request, LeaveApplication $leave): JsonResponse
    {
        if ($leave->status !== 'pending') return response()->json(['message' => 'Application already ' . $leave->status . '.'], 422);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes' => 'nullable|string|max:500',
        ]);
        $leave->update(['status' => $data['decision'], 'decided_by' => $request->user()->id, 'decision_notes' => $data['notes'] ?? null]);
        return response()->json(['data' => $leave->fresh(['staff:id,first_name,last_name,employee_no', 'decider:id,name'])]);
    }

    public function summary(): JsonResponse
    {
        $byDept = Staff::query()->selectRaw('department_id, count(*) c')->whereNotNull('department_id')->groupBy('department_id')->pluck('c', 'department_id');
        $departments = Department::query()->get(['id', 'name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'staff' => (int) $byDept->get($d->id, 0)])
            ->values();

        $today = now()->toDateString();
        return response()->json(['data' => [
            'headcount' => Staff::count(),
            'active' => Staff::where('status', 'active')->count(),
            'probation' => Staff::where('status', 'probation')->count(),
            'monthly_cost' => round((float) Staff::whereNotIn('status', ['resigned', 'terminated'])->sum('base_salary'), 2),
            'departments' => $departments,
            'pending_leave' => LeaveApplication::with('staff:id,first_name,last_name,employee_no')
                ->where('status', 'pending')->latest('created_at')->limit(10)->get(),
            'on_leave_today' => LeaveApplication::where('status', 'approved')
                ->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->count(),
        ]]);
    }

    /** Approved days per leave type for the current calendar year, against the configured entitlement. */
    private function leaveSummary(Staff $staff): array
    {
        $ctx = app(TenantContext::class);
        $entitlement = (array) (Setting::getFor($ctx->organizationId ?: $staff->organization_id, $staff->campus_id, 'hr.leave_entitlement')
            ?? ['annual' => 14, 'casual' => 10, 'sick' => 12, 'earned' => 15]);

        $used = LeaveApplication::where('staff_id', $staff->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '>=', date('Y-01-01'))
            ->selectRaw('type, sum(days) d')
            ->groupBy('type')
            ->pluck('d', 'type');

        $out = [];
        foreach ($entitlement as $type => $allowed) {
            $taken = round((float) $used->get($type, 0), 2);
            $out[] = ['type' => $type, 'allowed' => (float) $allowed, 'used' => $taken, 'balance' => round((float) $allowed - $taken, 2)];
        }
        return $out;
    }

    /** Working days between two dates, skipping the campus week-off (Pakistan: Sunday). */
    private function countableDays(Carbon $from, Carbon $to, TenantContext $ctx): float
    {
        $weekOff = strtolower((string) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'hr.week_off') ?? 'sunday'));
        $map = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $off = $map[$weekOff] ?? 0;

        $days = 0;
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($to->copy()->startOfDay())) {
            if ($cursor->dayOfWeek !== $off) $days++;
            $cursor->addDay();
        }
        return round($days, 2);
    }

    private function nextEmployeeNo(string $campusCode): string
    {
        $like = "S-{$campusCode}-%";
        $max = DB::table('staff')->where('employee_no', 'like', $like)->max('employee_no');
        $next = ((int) ($max ? substr($max, -4) : 0)) + 1;
        return sprintf('S-%s-%04d', $campusCode, $next);
    }
}
