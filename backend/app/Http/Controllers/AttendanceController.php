<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEntry;
use App\Services\GuardianNotifier;
use App\Support\AttendanceDays;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function __construct(private GuardianNotifier $notifier)
    {
    }

    public function roster(Request $request): JsonResponse
    {
        if (! app(TenantContext::class)->campusId()) {
            return response()->json(['message' => 'Select a campus before viewing attendance.'], 422);
        }
        $data = $request->validate([
            'date' => 'required|date',
            'subject_type' => ['required', 'in:student,staff'],
            'section_id' => 'nullable|required_if:subject_type,student|exists:sections,id',
            // Which sheet to read back: 0 (or absent) is the day register, 1-12 a single period.
            'period_no' => 'nullable|integer|min:0|max:12',
        ]);
        $date = $data['date'];
        $period = (int) ($data['period_no'] ?? 0);

        $rows = $data['subject_type'] === 'student'
            ? $this->studentRoster((int) $data['section_id'])
            : $this->staffRoster();

        $marked = AttendanceRecord::where('subject_type', $data['subject_type'])
            ->where('date', $date)->where('period_no', $period)
            ->whereIn('subject_id', array_column($rows, 'id'))
            ->pluck('status', 'subject_id');

        return response()->json([
            'data' => array_map(
                fn (array $r) => $r + ['status' => $marked[$r['id']] ?? null, 'marked' => $marked->has($r['id'])],
                $rows,
            ),
            'period_no' => $period,
        ]);
    }

    private function studentRoster(int $sectionId): array
    {
        return Student::where('section_id', $sectionId)->where('status', 'active')
            ->orderBy('roll_no')->get(['id', 'roll_no', 'first_name', 'last_name'])
            ->map(fn (Student $s) => [
                'id' => $s->id, 'roll_no' => $s->roll_no,
                'first_name' => $s->first_name, 'last_name' => $s->last_name,
                'name' => trim("$s->first_name $s->last_name"),
            ])->all();
    }

    /**
     * The staff register is the campus HR list — teachers, admin and support alike —
     * not the set of accounts that can sign in, which excludes the guard and cleaner
     * and used to include the parents' logins.
     */
    private function staffRoster(): array
    {
        return Staff::with('designation:id,name')
            ->whereIn('status', ['active', 'probation'])
            ->orderBy('first_name')->orderBy('last_name')
            ->get()
            ->map(fn (Staff $s) => [
                'id' => $s->id,
                'name' => trim("$s->first_name $s->last_name"),
                'employee_no' => $s->employee_no,
                'designation' => $s->designation?->name,
                'department' => $s->department?->name,
            ])->all();
    }

    public function mark(Request $request): JsonResponse
    {
        if (! app(TenantContext::class)->campusId()) {
            return response()->json(['message' => 'Select a campus before marking attendance.'], 422);
        }
        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'subject_type' => ['required', 'in:student,staff'],
            'period_no' => 'nullable|integer|min:0|max:12',
            'records' => 'required|array|min:1',
            'records.*.subject_id' => 'required|integer',
            'records.*.status' => ['required', 'in:' . implode(',', AttendanceRecord::STATUSES)],
            'records.*.remarks' => 'nullable|string|max:255',
        ]);
        $subjectIds = collect($data['records'])->pluck('subject_id');
        $isStaff = $data['subject_type'] === 'staff';
        $user = $request->user();
        // Two registers, two owners: teachers mark the class roll, HR and the principal mark the staff one.
        if ($isStaff ? !$user->hasPermission('attendance.manage') : !$user->hasPermission('attendance.mark')) {
            return response()->json(['message' => $isStaff
                ? 'The staff register is kept by HR or the principal.'
                : 'Only a class teacher may mark the register.'], 403);
        }
        $pool = $isStaff
            ? Staff::whereIn('id', $subjectIds)->pluck('id')
            : Student::whereIn('id', $subjectIds)->pluck('id');
        if ($pool->count() !== count(array_unique($subjectIds->all()))) {
            return response()->json(['message' => 'One or more subjects do not exist in your campus.'], 422);
        }
        $ctx = app(TenantContext::class);
        if (strtotime($data['date']) > time()) return response()->json(['message' => 'Cannot mark future dates.'], 422);

        $period = $data['period_no'] ?? 0;
        $markedBy = $request->user()->id;
        $count = 0;
        DB::transaction(function () use ($data, $ctx, $period, $markedBy, &$count) {
            foreach ($data['records'] as $r) {
                AttendanceRecord::updateOrCreate(
                    [
                        'subject_type' => $data['subject_type'],
                        'subject_id' => $r['subject_id'],
                        'date' => $data['date'],
                        'period_no' => $period,
                    ],
                    [
                        'organization_id' => $ctx->organizationId,
                        'campus_id' => $ctx->campusId(),
                        'status' => $r['status'],
                        'remarks' => $r['remarks'] ?? null,
                        'marked_by' => $markedBy,
                    ],
                );
                $count++;
            }
            if ($data['subject_type'] === 'student' && (int) $period === 0 && $data['date'] === now()->toDateString()) {
                $this->recordRollCallArrivals(collect($data['records']), $markedBy);
            }
        });
        return response()->json(['message' => "Marked attendance for $count " . ($period ? "period(s)" : 'record(s)')]);
    }

    /**
     * A day register marked present today is proof the child was at school, so it fills
     * the gate log for families whose campus has no desk tap. Period rolls are ignored:
     * they say nothing about a child who was absent for the rest of the day.
     */
    private function recordRollCallArrivals(Collection $records, int $markedBy): void
    {
        $arrived = $records->whereIn('status', ['present', 'late'])->pluck('subject_id');
        if ($arrived->isEmpty()) return;

        $alreadyIn = StudentEntry::whereIn('student_id', $arrived)->where('direction', 'in')
            ->whereDate('at', now()->toDateString())->pluck('student_id');

        foreach ($arrived->diff($alreadyIn) as $studentId) {
            $entry = StudentEntry::create([
                'student_id' => $studentId,
                'direction' => 'in',
                'at' => now(),
                'source' => 'roll_call',
                'remarks' => 'Recorded from the class register',
                'recorded_by' => $markedBy,
            ]);
            $this->notifier->entry($entry);
        }
    }

    public function monthly(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'subject_type' => ['required', 'in:student,staff'],
            'section_id' => 'nullable|required_if:subject_type,student|exists:sections,id',
        ]);
        $threshold = (int) ($this->Setting($request->user()->organization_id, app(TenantContext::class)->campusId(), 'attendance.warning_threshold') ?? 75);

        $month = $data['month'];
        // Roll the rows up to one status per day first: a class-period roll call and the
        // day register describe the same attendance, and counting both would halve the
        // recorded days for a class whose teacher takes roll period by period.
        $days = AttendanceRecord::query()
            ->where('subject_type', $data['subject_type'])
            ->where('date', '>=', "$month-01")->where('date', '<=', "$month-31")
            ->when($data['subject_type'] === 'student' && ($data['section_id'] ?? null), fn ($q) => $q->whereIn('subject_id', Student::where('section_id', $data['section_id'])->pluck('id')))
            ->selectRaw('subject_id, date, ' . AttendanceDays::dayStatus() . ' as day_status')
            ->groupBy('subject_id', 'date');

        $rows = DB::query()
            ->fromSub($days, 'days')
            ->selectRaw('subject_id, ' . AttendanceDays::rollup())
            ->groupBy('subject_id')
            ->get();

        $ids = $rows->pluck('subject_id');
        if ($data['subject_type'] === 'student') {
            // Direct table read: attendance may outlive or pre-date scope-visible
            // student state, so names must resolve regardless of status.
            $names = DB::table('students')->whereIn('id', $ids)
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($s) => [$s->id => trim("$s->first_name $s->last_name")]);
        } else {
            $names = DB::table('staff')->whereIn('id', $ids)
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($s) => [$s->id => trim("$s->first_name $s->last_name")]);
        }

        $summary = $rows->map(function ($r) use ($names, $threshold) {
            $pct = $r->total > 0 ? round(($r->present / $r->total) * 100, 1) : 0;
            return [
                'subject_id' => $r->subject_id,
                'name' => $names[$r->subject_id] ?? "#{$r->subject_id}",
                'present' => (int) $r->present, 'absent' => (int) $r->absent,
                'leave' => (int) $r->leave_days, 'excused' => (int) $r->excused, 'total' => (int) $r->total,
                'percentage' => $pct,
                'warning' => $pct < $threshold,
            ];
        });

        return response()->json(['data' => $summary]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'in:student,staff'],
            'subject_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        $q = AttendanceRecord::where('subject_type', $data['subject_type'])->where('subject_id', $data['subject_id'])
            ->where('period_no', 0)->orderByDesc('date');
        if ($data['from'] ?? null) $q->where('date', '>=', $data['from']);
        if ($data['to'] ?? null) $q->where('date', '<=', $data['to']);
        return response()->json(['data' => $q->limit(400)->get()]);
    }

    private function Setting(int $orgId, ?int $campusId, string $key)
    {
        return \App\Models\Setting::getFor($orgId, $campusId, $key);
    }
}
