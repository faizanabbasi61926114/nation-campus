<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Student;
use App\Support\AttendanceDays;
use App\Support\Grading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The family side of the system. Every other controller partitions data by
 * campus; these endpoints partition by household first, because a guardian must
 * never reach another family's child even when both children are on one campus.
 */
class GuardianController extends Controller
{
    /** GET /guardian/wards — one card per child, with the figures a parent scans first. */
    public function wards(Request $request): JsonResponse
    {
        $wards = $this->wardsOf($request)->get();
        if ($wards->isEmpty()) return response()->json(['data' => []]);

        $ids = $wards->pluck('id');
        $org = (int) $request->user()->organization_id;
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        $monthly = $this->attendanceByMonth($org, $ids, $month, $month);
        $markedToday = DB::table('attendance_records')
            ->where('subject_type', 'student')->where('date', $today)->whereIn('subject_id', $ids)
            ->orderByDesc('id')->get(['subject_id', 'status'])
            ->groupBy('subject_id')->map(fn (Collection $g) => $g->first()->status);

        $gate = DB::table('student_entries')->where('organization_id', $org)
            ->whereIn('student_id', $ids)->whereDate('at', $today)
            ->orderBy('at')->orderBy('id')->get(['student_id', 'direction', 'at', 'gate', 'source'])
            ->groupBy('student_id')->map->last();

        $fees = DB::table('fee_invoices')
            ->where('organization_id', $org)->whereIn('student_id', $ids)
            ->whereNotIn('status', ['cancelled', 'waived'])
            ->selectRaw('student_id,
                sum(amount + gst_amount - discount + late_fee) as billed,
                sum((select sum(p.amount) from fee_payments p where p.invoice_id = fee_invoices.id and p.status = \'cleared\')) as paid,
                sum(case when due_date < ? and status in (\'unpaid\',\'partial\') then 1 else 0 end) as overdue', [$today])
            ->groupBy('student_id')->get()->keyBy('student_id');

        $results = $this->publishedResults($org, $ids)->groupBy('student_id')->map->first();
        $facts = $this->wardFacts($wards);

        return response()->json(['data' => $wards->map(function (Student $w) use ($monthly, $markedToday, $fees, $results, $facts, $gate, $month) {
            $summary = $monthly->get($w->id)?->get($month);
            $fee = $fees->get($w->id);
            $result = $results->get($w->id);
            $tap = $gate->get($w->id);
            $balance = $fee ? round(max(0, (float) $fee->billed - (float) $fee->paid), 2) : 0.0;
            return [
                'id' => $w->id,
                'name' => trim("$w->first_name $w->last_name"),
                'roll_no' => $w->roll_no,
                'admission_no' => $w->admission_no,
                'status' => $w->status,
                ...$facts->get($w->id),
                'attendance' => $summary ? $this->percentage($summary) : null,
                'today' => $markedToday->get($w->id),
                'gate' => $tap ? [
                    'direction' => $tap->direction,
                    'at' => $tap->at,
                    'time' => \Carbon\Carbon::parse($tap->at)->isoFormat('h:mm A'),
                    'gate' => $tap->gate,
                    'source' => $tap->source,
                ] : null,
                'fees' => [
                    'billed' => (float) ($fee->billed ?? 0),
                    'paid' => (float) ($fee->paid ?? 0),
                    'balance' => $balance,
                    'overdue_invoices' => (int) ($fee->overdue ?? 0),
                    'alert' => $balance > 0 && (int) ($fee->overdue ?? 0) > 0,
                ],
                'latest_result' => $result ? [
                    'exam_id' => (int) $result->exam_id,
                    'exam' => $result->name,
                    'start_date' => $result->start_date,
                    'percentage' => Grading::percent((float) $result->total, $result->max_marks),
                    'grade' => $this->grade($result, (float) $result->total),
                ] : null,
            ];
        })->values()]);
    }

    /** GET /guardian/wards/{student}/attendance?month=Y-m */
    public function attendance(Request $request, int $student): JsonResponse
    {
        $ward = $this->assertWard($request, $student);
        $month = $request->validate(['month' => 'nullable|date_format:Y-m'])['month'] ?? now()->format('Y-m');
        $org = (int) $ward->organization_id;
        $threshold = (int) (Setting::getFor($org, $ward->campus_id, 'attendance.warning_threshold') ?? 75);

        $trend = $this->attendanceByMonth($org, collect([$ward->id]), now()->copy()->startOfMonth()->subMonths(5)->format('Y-m'), $month);
        $byMonth = $trend->get($ward->id) ?? collect();
        $summary = $byMonth->get($month);
        if ($summary) {
            $summary = $this->percentage($summary);
            $summary['warning'] = $summary['percentage'] < $threshold;
        }

        $days = DB::table('attendance_records')
            ->where('subject_type', 'student')->where('subject_id', $ward->id)
            ->where('date', '>=', "$month-01")->where('date', '<=', "$month-31")
            ->orderByDesc('date')->orderByDesc('period_no')
            ->get(['date', 'period_no', 'status', 'remarks'])
            ->map(fn ($r) => [
                'date' => $r->date, 'period_no' => (int) $r->period_no,
                'status' => $r->status, 'remarks' => $r->remarks,
            ]);

        return response()->json(['data' => [
            'ward' => $this->wardRef($ward),
            'month' => $month,
            'threshold' => $threshold,
            'summary' => $summary,
            'days' => $days,
            'trend' => $byMonth->map(fn ($r, $ym) => ['month' => $ym] + $this->percentage($r))->values(),
        ]]);
    }

    /**
     * GET /guardian/wards/{student}/entries — the gate log for one child, day by day.
     * A family reads this to see the tap times the alert only summarised.
     */
    public function entries(Request $request, int $student): JsonResponse
    {
        $ward = $this->assertWard($request, $student);

        $taps = DB::table('student_entries')
            ->where('organization_id', (int) $ward->organization_id)
            ->where('student_id', $ward->id)
            ->orderByDesc('at')->orderByDesc('id')->limit(200)
            ->get(['direction', 'at', 'gate', 'source', 'remarks']);

        $days = $taps->groupBy(fn (object $r) => substr($r->at, 0, 10))->map(function (Collection $day, string $date) {
            return [
                'date' => $date,
                // Newest tap decides where the child is at the end of the day.
                'state' => $day->first()->direction === 'in' ? 'inside' : 'away',
                'taps' => $day->map(fn (object $r) => [
                    'direction' => $r->direction,
                    'time' => \Carbon\Carbon::parse($r->at)->isoFormat('h:mm A'),
                    'gate' => $r->gate,
                    'source' => $r->source,
                    'remarks' => $r->remarks,
                ])->values()->all(),
            ];
        })->values();

        return response()->json(['data' => ['ward' => $this->wardRef($ward), 'days' => $days]]);
    }

    /** GET /guardian/wards/{student}/fees */
    public function fees(Request $request, int $student): JsonResponse
    {
        $ward = $this->assertWard($request, $student);
        $today = now()->toDateString();

        $invoices = DB::table('fee_invoices')
            ->where('organization_id', (int) $ward->organization_id)->where('student_id', $ward->id)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('issue_date')->orderByDesc('id')->limit(24)
            ->get(['id', 'title', 'session_month', 'amount', 'gst_amount', 'discount', 'late_fee',
                'issue_date', 'due_date', 'status'])
            ->keyBy('id');

        $payments = DB::table('fee_payments')
            ->whereIn('invoice_id', $invoices->keys()->all() ?: [0])
            ->orderByDesc('paid_on')->orderByDesc('id')
            ->get(['invoice_id', 'receipt_no', 'amount', 'channel', 'paid_on', 'status'])
            ->map(fn ($p) => [
                'invoice_id' => (int) $p->invoice_id, 'receipt_no' => $p->receipt_no,
                'amount' => (float) $p->amount, 'channel' => $p->channel,
                'paid_on' => $p->paid_on, 'status' => $p->status,
            ]);

        $rows = $invoices->map(function (object $i) use ($payments, $today) {
            $due = round((float) $i->amount + (float) $i->gst_amount + (float) $i->late_fee - (float) $i->discount, 2);
            $paid = round($payments->where('invoice_id', $i->id)->where('status', 'cleared')->sum('amount'), 2);
            return [
                'id' => $i->id, 'title' => $i->title, 'session_month' => $i->session_month,
                'amount' => (float) $i->amount, 'gst_amount' => (float) $i->gst_amount,
                'discount' => (float) $i->discount, 'late_fee' => (float) $i->late_fee,
                'total_due' => $due, 'paid' => $paid, 'balance' => round(max(0, $due - $paid), 2),
                'issue_date' => $i->issue_date, 'due_date' => $i->due_date, 'status' => $i->status,
                'is_overdue' => $i->due_date < $today && in_array($i->status, ['unpaid', 'partial'], true),
                'payments' => $payments->where('invoice_id', $i->id)->values()->all(),
            ];
        })->values();

        // A waived paper was never owed, so it stays out of the money summary and in the list.
        $owed = $rows->reject(fn ($r) => in_array($r['status'], ['waived'], true));

        return response()->json(['data' => [
            'ward' => $this->wardRef($ward),
            'summary' => [
                'billed' => round($owed->sum('total_due'), 2),
                'paid' => round($owed->sum('paid'), 2),
                'balance' => round(max(0, $owed->sum('total_due') - $owed->sum('paid')), 2),
                'overdue_balance' => round($owed->where('is_overdue', true)->sum('balance'), 2),
                'waived' => round($rows->where('status', 'waived')->sum('total_due'), 2),
                'currency' => (string) (Setting::getFor((int) $ward->organization_id, $ward->campus_id, 'fees.currency') ?? config('cms.currency')),
            ],
            'invoices' => $rows,
            'payments' => $payments->values()->all(),
        ]]);
    }

    /** GET /guardian/wards/{student}/results — published papers only; uncounted marks stay invisible. */
    public function results(Request $request, int $student): JsonResponse
    {
        $ward = $this->assertWard($request, $student);
        $org = (int) $ward->organization_id;
        $exams = $this->publishedResults($org, collect([$ward->id]));
        if ($exams->isEmpty()) {
            return response()->json(['data' => ['ward' => $this->wardRef($ward), 'results' => []]]);
        }

        $subjects = DB::table('exam_marks as m')
            ->join('exam_subjects as es', 'es.id', '=', 'm.exam_subject_id')
            ->join('subjects as s', 's.id', '=', 'es.subject_id')
            ->where('m.student_id', $ward->id)->whereIn('m.exam_id', $exams->pluck('exam_id'))
            ->selectRaw('m.exam_id, s.name as subject, es.max_marks, es.pass_marks, m.marks_obtained, m.is_absent')
            ->orderBy('s.name')->get()->groupBy('exam_id');

        $results = $exams->map(function (object $e) use ($subjects, $org) {
            $rows = $subjects->get($e->exam_id, collect());
            $total = (float) $e->total;
            $failed = $rows->filter(fn ($r) => !((float) ($r->marks_obtained ?? 0) >= (float) $r->pass_marks))->count();
            return [
                'exam_id' => (int) $e->exam_id,
                'exam' => $e->name,
                'type' => $e->type,
                'start_date' => $e->start_date,
                'percentage' => Grading::percent($total, $e->max_marks),
                'grade' => $this->grade($e, $total),
                'remark' => Grading::remark($org, $e->campus_id ? (int) $e->campus_id : null, $total, $e->max_marks),
                'total_marks' => $total,
                'max_marks' => (float) $e->max_marks,
                'absent_count' => (int) $e->absent,
                'result' => $failed === 0 ? 'Pass' : "Fail ($failed subject(s))",
                'subjects' => $rows->map(fn ($r) => [
                    'subject' => $r->subject,
                    'marks_obtained' => $r->is_absent ? null : (float) $r->marks_obtained,
                    'max_marks' => (float) $r->max_marks,
                    'pass_marks' => (float) $r->pass_marks,
                    'is_absent' => (bool) $r->is_absent,
                    'passed' => (float) ($r->marks_obtained ?? 0) >= (float) $r->pass_marks,
                ])->values(),
            ];
        })->values();

        return response()->json(['data' => ['ward' => $this->wardRef($ward), 'results' => $results]]);
    }

    // ---------------------------------------------------------------------- helpers

    /**
     * The household boundary. A student id that is not this user's child fails here
     * as a 404, before any campus, fee or marks query runs.
     */
    private function assertWard(Request $request, int $studentId): Student
    {
        return $request->user()->wardQuery()->findOrFail($studentId);
    }

    private function wardsOf(Request $request)
    {
        return $request->user()->wardQuery()->orderBy('first_name')->orderBy('last_name');
    }

    private function wardRef(Student $w): array
    {
        return [
            'id' => $w->id,
            'name' => trim("$w->first_name $w->last_name"),
            'roll_no' => $w->roll_no,
            'admission_no' => $w->admission_no,
        ] + $this->wardFacts(collect([$w]))->first();
    }

    /**
     * Campus, class, section and class-teacher names for a set of wards. Read straight
     * from the tables so a child enrolled outside the guardian's active campus still
     * resolves instead of coming back blank through the tenant scope.
     */
    private function wardFacts(Collection $wards): Collection
    {
        $campuses = DB::table('campuses')
            ->whereIn('id', $wards->pluck('campus_id')->filter()->all() ?: [0])
            ->get(['id', 'name', 'code'])->keyBy('id');
        $classes = DB::table('classes')
            ->whereIn('id', $wards->pluck('class_id')->filter()->all() ?: [0])
            ->pluck('name', 'id');
        $sections = DB::table('sections')
            ->whereIn('id', $wards->pluck('section_id')->filter()->all() ?: [0])
            ->get(['id', 'name', 'class_teacher_id'])->keyBy('id');
        $teachers = DB::table('users')
            ->whereIn('id', $sections->pluck('class_teacher_id')->filter()->all() ?: [0])
            ->pluck('name', 'id');

        return $wards->keyBy('id')->map(fn (Student $w) => [
            'campus' => ($c = $campuses->get($w->campus_id)) ? ['id' => $c->id, 'name' => $c->name, 'code' => $c->code] : null,
            'class' => $classes->get($w->class_id),
            'section' => $sections->get($w->section_id)?->name,
            'class_teacher' => $teachers->get($sections->get($w->section_id)?->class_teacher_id),
        ]);
    }

    /** @return Collection<int, Collection<string, object>> keyed [student_id][Y-m] */
    private function attendanceByMonth(int $org, Collection $studentIds, string $from, string $to): Collection
    {
        $days = DB::table('attendance_records')
            ->where('organization_id', $org)
            ->where('subject_type', 'student')
            ->whereIn('subject_id', $studentIds)
            ->where('date', '>=', "$from-01")->where('date', '<=', "$to-31")
            ->selectRaw('subject_id, substr(date, 1, 7) as ym, ' . AttendanceDays::dayStatus() . ' as day_status')
            ->groupBy('subject_id')
            ->groupByRaw('substr(date, 1, 7), date');

        return DB::query()
            ->fromSub($days, 'days')
            ->selectRaw('subject_id, ym, ' . AttendanceDays::rollup())
            ->groupBy('subject_id', 'ym')
            ->orderBy('ym')
            ->get()
            ->groupBy('subject_id')->map(fn (Collection $g) => $g->keyBy('ym'));
    }

    private function percentage(object $row): array
    {
        $total = (int) $row->total;
        return [
            'present' => (int) $row->present, 'absent' => (int) $row->absent,
            'leave' => (int) $row->leave_days, 'excused' => (int) $row->excused,
            'late' => (int) $row->late, 'total' => $total,
            'percentage' => $total > 0 ? round((int) $row->present / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * One row per (student, published exam) with marks already totalled, newest paper first.
     * A paper in completed-but-unpublished state stays invisible to the family.
     */
    private function publishedResults(int $org, Collection $studentIds): Collection
    {
        return DB::table('exam_marks as m')
            ->join('exams as e', 'e.id', '=', 'm.exam_id')
            ->join('exam_subjects as es', 'es.id', '=', 'm.exam_subject_id')
            ->where('e.organization_id', $org)
            ->where('e.status', 'published')
            ->whereIn('m.student_id', $studentIds)
            ->selectRaw('m.student_id, e.id as exam_id, e.name, e.type, e.start_date, e.campus_id, e.organization_id,
                sum(coalesce(m.marks_obtained, 0)) as total, sum(es.max_marks) as max_marks,
                sum(case when m.is_absent = 1 then 1 else 0 end) as absent')
            ->groupBy('m.student_id', 'e.id', 'e.name', 'e.type', 'e.start_date', 'e.campus_id', 'e.organization_id')
            ->orderByDesc('e.start_date')->orderByDesc('e.id')
            ->get();
    }

    /** Graded against the ward's own campus scale, so the letter matches the report card. */
    private function grade(object $examRow, float $total): string
    {
        return Grading::label(
            (int) $examRow->organization_id,
            $examRow->campus_id ? (int) $examRow->campus_id : null,
            $total,
            $examRow->max_marks,
        );
    }
}
