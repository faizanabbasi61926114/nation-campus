<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Support\AttendanceDays;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
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

        if ($data['subject_type'] === 'student') {
            $students = Student::where('section_id', $data['section_id'])->where('status', 'active')
                ->orderBy('roll_no')->get(['id', 'roll_no', 'first_name', 'last_name']);
        } else {
            $ctx = app(TenantContext::class);
            $students = User::where('organization_id', $ctx->organizationId)
                ->where('is_active', true)
                ->when($ctx->campusId(), fn ($q) => $q->where('campus_id', $ctx->campusId()))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $marked = AttendanceRecord::where('subject_type', $data['subject_type'])
            ->where('date', $date)->where('period_no', $period)
            ->whereIn('subject_id', $students->pluck('id'))
            ->pluck('status', 'subject_id');

        return response()->json([
            'data' => $students->map(fn ($s) => array_merge($s->toArray(), [
                'status' => $marked[$s->id] ?? null,
                'marked' => $marked->has($s->id),
            ])),
            'period_no' => $period,
        ]);
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
        $pool = $data['subject_type'] === 'student' ? Student::whereIn('id', collect($data['records'])->pluck('subject_id'))->pluck('id') : User::whereIn('id', collect($data['records'])->pluck('subject_id'))->pluck('id');
        if ($pool->count() !== count(array_unique(collect($data['records'])->pluck('subject_id')->all()))) {
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
        });
        return response()->json(['message' => "Marked attendance for $count " . ($period ? "period(s)" : 'record(s)')]);
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
            $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');
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
