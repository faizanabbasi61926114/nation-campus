<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Admission;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\CommunicationLog;
use App\Models\Exam;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeeWaiver;
use App\Models\JournalLine;
use App\Models\JournalVoucher;
use App\Models\LeaveApplication;
use App\Models\Message;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One call that answers "what needs my attention today?" for the signed-in role.
 *
 * Every figure is produced by a tenant-scoped query, so a campus user's dashboard can
 * never carry head-office totals, and a section is simply absent when the caller's role
 * has no right to see that module — the payload is built from permissions, not filtered
 * afterwards.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly TenantContext $ctx) {}

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $can = fn (string $perm): bool => $me->hasPermission($perm);

        $sections = [];
        if ($can('students.view'))    $sections['students'] = $this->students();
        if ($can('admissions.view'))  $sections['admissions'] = $this->admissions();
        if ($can('attendance.view'))  $sections['attendance'] = $this->attendance($can('hr.view'));
        if ($can('exams.view'))       $sections['exams'] = $this->exams();
        if ($can('fees.view'))        $sections['fees'] = $this->fees();
        if ($can('hr.view'))          $sections['hr'] = $this->hr();
        if ($can('payroll.view'))     $sections['payroll'] = $this->payroll();
        if ($can('accounts.view'))    $sections['accounts'] = $this->accounts();
        if ($can('communication.view')) $sections['communication'] = $this->communication($me->id);
        if ($can('audit.view'))       $sections['audit'] = $this->audit();

        return response()->json(['data' => [
            'generated_at' => now()->toDateTimeString(),
            'scope' => [
                'organization_id' => $this->ctx->organizationId,
                'campus' => $this->ctx->campus
                    ? ['id' => $this->ctx->campus->id, 'name' => $this->ctx->campus->name, 'code' => $this->ctx->campus->code]
                    : null,
                'cross_campus' => $this->ctx->campus === null,
            ],
            'viewer' => [
                'name' => $me->name,
                'roles' => $me->roleAssignments->pluck('slug')->unique()->values(),
                'campuses' => Campus::whereIn('id', $me->accessibleCampusIds())->orderBy('name')->pluck('name'),
            ],
            'sections' => (object) $sections,
            'alerts' => $this->alerts($sections, $can),
            'campus_breakdown' => $this->breakdown($can),
        ]]);
    }

    // -------------------------------------------------------------------- sections

    private function students(): array
    {
        return [
            'active' => Student::where('status', 'active')->count(),
            'admitted_this_month' => Student::whereBetween('admission_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])->count(),
            'awaiting_class' => Student::where('status', 'active')->whereNull('section_id')->count(),
            'graduated' => Student::where('status', 'graduated')->count(),
            'withdrawn' => Student::whereIn('status', ['withdrawn', 'inactive'])->count(),
            'by_gender' => Student::where('status', 'active')->selectRaw('gender, count(*) c')->groupBy('gender')->pluck('c', 'gender'),
        ];
    }

    private function admissions(): array
    {
        // The pipeline has three different owners: the admissions desk, the family, the office.
        $awaiting = fn (array $statuses) => Admission::whereIn('status', $statuses)->count();
        return [
            'awaiting_review' => $awaiting(['pending', 'screening', 'interview', 'test', 'waitlisted']),
            'awaiting_family' => $awaiting(['offered']),
            'awaiting_enrolment' => $awaiting(['accepted']),
            'enrolled_this_month' => Admission::where('status', 'enrolled')
                ->whereBetween('decided_at', [now()->startOfMonth(), now()])->count(),
            'closed_this_month' => Admission::whereIn('status', ['rejected', 'withdrawn'])
                ->whereBetween('decided_at', [now()->startOfMonth(), now()])->count(),
            'by_status' => Admission::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ];
    }

    private function attendance(bool $withStaff): array
    {
        $today = now()->toDateString();
        // Status literals are inlined: setBindings() would discard the where() bindings.
        $day = AttendanceRecord::where('subject_type', 'student')->whereDate('date', $today)
            ->selectRaw('count(*) marks, count(distinct subject_id) students, '
                . "sum(case when status in ('present','late') then 1 else 0 end) present, "
                . "sum(case when status = 'absent' then 1 else 0 end) absent, "
                . "sum(case when status = 'late' then 1 else 0 end) late, "
                . "sum(case when status in ('leave','excused') then 1 else 0 end) approved")
            ->first();

        $expected = Student::where('status', 'active')->count();
        $marked = (int) ($day?->students ?? 0);

        return [
            'date' => $today,
            'students_expected' => $expected,
            'students_marked' => $marked,
            'unmarked' => max(0, $expected - $marked),
            'present' => (int) ($day?->present ?? 0),
            'absent' => (int) ($day?->absent ?? 0),
            'late' => (int) ($day?->late ?? 0),
            'approved' => (int) ($day?->approved ?? 0),
            'present_percent' => $day && $day->marks > 0 ? round($day->present / $day->marks * 100, 1) : null,
            'trend' => $this->attendanceTrend(),
            'at_risk' => $this->atRiskStudents(),
            // Staff marks and leave counts stay with the HR-permitted viewer.
            'staff' => $withStaff ? $this->staffAttendance($today) : null,
        ];
    }

    /** Daily attendance percentage for the last 14 calendar days, oldest first. */
    private function attendanceTrend(): array
    {
        $from = now()->subDays(13)->toDateString();
        $rows = AttendanceRecord::where('subject_type', 'student')->whereBetween('date', [$from, now()->toDateString()])
            ->selectRaw('date, count(*) marks, sum(case when status in (\'present\',\'late\') then 1 else 0 end) present')
            // toBase(): hydration would cast `date` to a Carbon and keyBy() would miss the lookups.
            ->groupBy('date')->toBase()->get()->keyBy('date');

        $trend = [];
        foreach (collect(range(0, 13))->map(fn ($i) => now()->subDays(13 - $i)->toDateString()) as $date) {
            $row = $rows->get($date);
            $trend[] = ['date' => $date, 'present_percent' => $row && $row->marks ? round($row->present / $row->marks * 100, 1) : null];
        }
        return $trend;
    }

    /**
     * Households the campus has to chase: below the configured threshold over the last
     * 30 days, with enough marks recorded for the percentage to mean something.
     */
    private function atRiskStudents(): array
    {
        $threshold = (float) (Setting::getFor($this->ctx->organizationId, $this->ctx->campusId(), 'attendance.warning_threshold') ?? 75);
        $from = now()->subDays(30)->toDateString();

        $rows = AttendanceRecord::where('subject_type', 'student')->whereBetween('date', [$from, now()->toDateString()])
            ->selectRaw('subject_id, count(*) days, sum(case when status in (\'present\',\'late\') then 1 else 0 end) present')
            ->groupBy('subject_id')->having('days', '>=', 5)->toBase()->get()
            ->map(fn ($r) => (object) [
                'subject_id' => $r->subject_id, 'days' => (int) $r->days,
                'percent' => round($r->present / $r->days * 100, 1),
            ])
            ->filter(fn ($r) => $r->percent < $threshold)
            ->sortBy('percent')->take(10);

        $students = Student::whereIn('id', $rows->pluck('subject_id'))->get(['id', 'first_name', 'last_name', 'roll_no', 'section_id'])
            ->keyBy('id');

        return [
            'threshold' => $threshold,
            'count' => $rows->count(),
            'students' => $rows->map(fn ($r) => [
                'student_id' => $r->subject_id,
                'name' => optional($students->get($r->subject_id))->first_name . ' ' . optional($students->get($r->subject_id))->last_name,
                'roll_no' => $students->get($r->subject_id)?->roll_no,
                'days' => $r->days,
                'present_percent' => $r->percent,
            ])->values()->all(),
        ];
    }

    private function staffAttendance(string $today): array
    {
        $row = AttendanceRecord::where('subject_type', 'staff')->whereDate('date', $today)
            ->selectRaw('count(*) marks, sum(case when status = \'present\' then 1 else 0 end) present')->first();
        return [
            'marked' => (int) ($row?->marks ?? 0),
            'present' => (int) ($row?->present ?? 0),
            'on_leave' => LeaveApplication::where('status', 'approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->count(),
        ];
    }

    private function exams(): array
    {
        return [
            'upcoming' => Exam::whereIn('status', ['scheduled', 'ongoing'])->orderBy('start_date')->limit(4)
                ->get(['id', 'name', 'type', 'start_date', 'end_date', 'status']),
            'awaiting_results' => Exam::where('status', 'completed')->count(),
            'published' => Exam::where('status', 'published')->count(),
            'draft' => Exam::where('status', 'draft')->count(),
        ];
    }

    private function fees(): array
    {
        $openIds = FeeInvoice::whereIn('status', ['unpaid', 'partial'])->pluck('id');
        $billed = (float) FeeInvoice::whereIn('status', ['unpaid', 'partial'])
            ->sum(DB::raw('amount + gst_amount + late_fee - discount'));
        $cleared = (float) FeePayment::where('status', 'cleared')->whereIn('invoice_id', $openIds)->sum('amount');

        $month = [now()->startOfMonth(), now()->endOfMonth()];
        $collected = FeePayment::where('status', 'cleared')->whereBetween('paid_on', $month);

        $today = now()->toDateString();
        return [
            'currency' => (string) (Setting::getFor($this->ctx->organizationId, $this->ctx->campusId(), 'fees.currency') ?? 'PKR'),
            'outstanding' => round(max(0, $billed - $cleared), 2),
            'outstanding_invoices' => $openIds->count(),
            'overdue_invoices' => FeeInvoice::whereIn('status', ['unpaid', 'partial'])->where('due_date', '<', $today)->count(),
            'collected_this_month' => round((float) (clone $collected)->sum('amount'), 2),
            'receipts_this_month' => (clone $collected)->count(),
            'waived_this_month' => round((float) FeeWaiver::where('status', 'approved')->whereBetween('created_at', $month)->sum('amount'), 2),
            'waivers_pending' => FeeWaiver::where('status', 'pending')->count(),
            'collected_trend' => collect(range(5, 0))->mapWithKeys(function ($back) {
                $m = now()->subMonthsNoOverflow($back);
                return [$m->toDateString() => round((float) FeePayment::where('status', 'cleared')
                    ->whereBetween('paid_on', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])->sum('amount'), 2)];
            })->values()->all(),
            'by_status' => FeeInvoice::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ];
    }

    private function hr(): array
    {
        $active = Staff::whereNotIn('status', ['resigned', 'terminated']);
        return [
            'headcount' => Staff::count(),
            'active' => (clone $active)->count(),
            'probation' => Staff::where('status', 'probation')->count(),
            'joined_this_month' => Staff::whereBetween('joined_on', [now()->startOfMonth()->toDateString(), now()->toDateString()])->count(),
            'monthly_cost' => round((float) (clone $active)->sum('base_salary'), 2),
            'leave_pending' => LeaveApplication::where('status', 'pending')->count(),
            'on_leave_today' => LeaveApplication::where('status', 'approved')
                ->whereDate('from_date', '<=', now()->toDateString())->whereDate('to_date', '>=', now()->toDateString())->count(),
        ];
    }

    private function payroll(): array
    {
        $run = PayrollRun::latest('month')->first();
        return [
            'current' => $run ? [
                'id' => $run->id, 'month' => $run->month, 'status' => $run->status,
                'gross' => round((float) $run->total_gross, 2), 'net' => round((float) $run->total_net, 2),
            ] : null,
            'awaiting_approval' => PayrollRun::whereIn('status', ['draft', 'calculated'])->count(),
            'paid_this_month' => PayrollRun::where('status', 'paid')->whereBetween('paid_on', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
    }

    private function accounts(): array
    {
        // Posted lines only: a draft voucher is not money until accounts posts it. The
        // whereHas runs through JournalVoucher, so the tenant scope filters for us.
        $lines = JournalLine::whereHas('voucher', fn ($q) => $q->where('status', 'posted')
            ->whereBetween('voucher_date', [now()->startOfMonth()->toDateString(), now()->toDateString()]))
            ->selectRaw('account_id, sum(debit) d, sum(credit) c')->groupBy('account_id')->get()
            ->keyBy('account_id');

        $net = fn (string $type): float => round((float) Account::where('type', $type)->get()
            ->sum(function ($a) use ($lines, $type) {
                $row = $lines->get($a->id);
                if (!$row) return 0;
                return $type === 'income' ? (float) $row->c - (float) $row->d : (float) $row->d - (float) $row->c;
            }), 2);

        $income = $net('income');
        $expense = $net('expense');

        return [
            'income_this_month' => $income,
            'expense_this_month' => $expense,
            'net_this_month' => round($income - $expense, 2),
            'vouchers_draft' => JournalVoucher::where('status', 'draft')->count(),
        ];
    }

    private function communication(int $meId): array
    {
        $week = now()->subWeek();
        $delivery = CommunicationLog::where('created_at', '>=', $week)
            ->selectRaw('channel, status, count(*) c')->groupBy('channel', 'status')->get()
            ->map(fn ($r) => ['channel' => $r->channel, 'status' => $r->status, 'count' => (int) $r->c])->values();

        return [
            'unread_messages' => Message::unread($meId)->count(),
            'threads_inbox' => Message::involving($meId)->count(),
            // Live notices inside the caller's campus scope. Audience targeting is
            // resolved by the announcements feed, not duplicated here.
            'announcements_live' => Announcement::live()->count(),
            'announcements_published_week' => Announcement::where('status', 'published')->where('published_at', '>=', $week)->count(),
            'announcements_draft' => Announcement::where('status', 'draft')->count(),
            'deliveries_last_week' => $delivery,
        ];
    }

    private function audit(): array
    {
        return [
            'recent' => AuditLog::with('user:id,name')->latest('id')->limit(6)
                ->get(['id', 'action', 'description', 'auditable_type', 'created_at', 'user_id']),
        ];
    }

    // ---------------------------------------------------------------------- alerts

    /** Actionable items, worst first, assembled from the sections the caller can see. */
    private function alerts(array $sections, callable $can): array
    {
        $out = [];
        $push = function (string $level, string $module, string $text, ?string $link = null) use (&$out): void {
            $out[] = ['level' => $level, 'module' => $module, 'text' => $text, 'link' => $link];
        };

        if ($a = $sections['attendance'] ?? null) {
            if ($a['unmarked'] > 0) {
                $push('critical', 'attendance', "Attendance is not marked for {$a['unmarked']} student(s) today.", '/attendance');
            }
            if ($a['at_risk']['count'] > 0) {
                $push('warning', 'attendance', "{$a['at_risk']['count']} student(s) are below the {$a['at_risk']['threshold']}% attendance threshold this month.", '/attendance');
            }
        }
        if ($f = $sections['fees'] ?? null) {
            if ($f['overdue_invoices'] > 0) {
                $push('critical', 'fees', "{$f['overdue_invoices']} invoice(s) are past their due date — " . number_format($f['outstanding']) . ' outstanding.', '/fees');
            }
            if ($f['waivers_pending'] > 0) {
                $push('info', 'fees', "{$f['waivers_pending']} fee waiver request(s) need a decision.", '/fees');
            }
        }
        if ($ad = $sections['admissions'] ?? null) {
            $open = $ad['awaiting_review'] + $ad['awaiting_enrolment'];
            if ($open > 0) {
                $push('warning', 'admissions', "{$open} admission application(s) need a decision or enrolment.", '/admissions');
            }
        }
        if ($s = $sections['students'] ?? null) {
            if ($s['awaiting_class'] > 0) {
                $push('info', 'students', "{$s['awaiting_class']} active student(s) are not attached to a class or section.", '/students');
            }
        }
        if ($h = $sections['hr'] ?? null) {
            if ($h['leave_pending'] > 0) {
                $push('info', 'hr', "{$h['leave_pending']} leave application(s) are waiting for approval.", '/staff');
            }
        }
        if ($p = $sections['payroll'] ?? null) {
            if ($p['current'] && in_array($p['current']['status'], ['draft', 'calculated'], true)) {
                $push('warning', 'payroll', 'Payroll for ' . date('F Y', strtotime($p['current']['month'])) . " is {$p['current']['status']} and has not been approved.", '/payroll');
            }
            if ($p['awaiting_approval'] > 1) {
                $push('info', 'payroll', "{$p['awaiting_approval']} payroll run(s) are open.", '/payroll');
            }
        }
        if ($v = $sections['accounts'] ?? null) {
            if ($v['vouchers_draft'] > 0) {
                $push('info', 'accounts', "{$v['vouchers_draft']} voucher(s) are drafted but not posted.", '/accounts');
            }
        }
        if ($c = $sections['communication'] ?? null) {
            $failed = collect($c['deliveries_last_week'])->whereIn('status', ['failed', 'skipped'])->sum('count');
            if ($failed > 0) {
                $push('warning', 'communication', "{$failed} message(s) could not be delivered last week; check the delivery log.", '/announcements');
            }
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($x, $y) => $rank[$x['level']] <=> $rank[$y['level']]);
        return $out;
    }

    /**
     * Head-office view: the same numbers per campus, and only for the modules the
     * caller is allowed to read. Campus users get null — their own figures are above.
     */
    private function breakdown(callable $can): ?array
    {
        if ($this->ctx->campus !== null || !$can('campuses.view')) return null;

        $org = $this->ctx->organizationId;
        $campuses = Campus::where('organization_id', $org)->where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']);
        $today = now()->toDateString();
        $month = [now()->startOfMonth()->toDateString(), now()->toDateString()];

        // Head-office rows are read across campuses, so the tenant scope is dropped and
        // the organization filter is written out by hand.
        $byCampus = fn (string $model, array $filters = []): array => $model::query()
            ->withoutGlobalScopes()->where('organization_id', $org)
            ->when($filters['statuses'] ?? null, fn ($q, $s) => $q->whereIn('status', $s))
            ->when($filters['closed_statuses'] ?? null, fn ($q, $s) => $q->whereNotIn('status', $s))
            ->selectRaw('campus_id, count(*) c')->groupBy('campus_id')->pluck('c', 'campus_id')->all();

        $students = $can('students.view') ? $byCampus(Student::class, ['statuses' => ['active']]) : [];
        $staff = $can('hr.view') ? $byCampus(Staff::class, ['statuses' => ['active', 'probation']]) : [];
        $admissions = $can('admissions.view') ? $byCampus(Admission::class, [
            'closed_statuses' => ['enrolled', 'rejected', 'withdrawn'],
        ]) : [];

        $present = $daily = [];
        if ($can('attendance.view')) {
            $rows = AttendanceRecord::withoutGlobalScopes()->where('organization_id', $org)->where('subject_type', 'student')
                ->whereDate('date', $today)
                ->selectRaw('campus_id, count(*) marks, sum(case when status in (\'present\',\'late\') then 1 else 0 end) present')
                ->groupBy('campus_id')->get();
            foreach ($rows as $r) {
                $present[$r->campus_id] = $r->marks ? round($r->present / $r->marks * 100, 1) : null;
                $daily[$r->campus_id] = (int) $r->marks;
            }
        }

        $fees = $collected = [];
        if ($can('fees.view')) {
            $openIds = FeeInvoice::withoutGlobalScopes()->where('organization_id', $org)
                ->whereIn('status', ['unpaid', 'partial'])->select('id');

            $billed = FeeInvoice::withoutGlobalScopes()->where('organization_id', $org)
                ->whereIn('status', ['unpaid', 'partial'])
                ->selectRaw('campus_id, sum(amount + gst_amount + late_fee - discount) s')->groupBy('campus_id')
                ->pluck('s', 'campus_id');
            // Same rule as the section total: part-paid invoices count at their balance.
            $paidOpen = FeePayment::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'cleared')
                ->whereIn('invoice_id', $openIds)->selectRaw('campus_id, sum(amount) s')->groupBy('campus_id')
                ->pluck('s', 'campus_id');
            foreach ($billed as $cid => $s) {
                $fees[$cid] = round(max(0, (float) $s - (float) ($paidOpen[$cid] ?? 0)), 2);
            }
            $paid = FeePayment::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'cleared')
                ->whereBetween('paid_on', $month)->selectRaw('campus_id, sum(amount) s')->groupBy('campus_id')->pluck('s', 'campus_id');
            foreach ($paid as $cid => $s) $collected[$cid] = round((float) $s, 2);
        }

        return $campuses->map(fn (Campus $c) => [
            'campus_id' => $c->id, 'name' => $c->name, 'code' => $c->code,
            'students' => $can('students.view') ? (int) ($students[$c->id] ?? 0) : null,
            'staff' => $can('hr.view') ? (int) ($staff[$c->id] ?? 0) : null,
            'admissions_pending' => $can('admissions.view') ? (int) ($admissions[$c->id] ?? 0) : null,
            'attendance_marks_today' => $can('attendance.view') ? (int) ($daily[$c->id] ?? 0) : null,
            'attendance_percent' => $can('attendance.view') ? ($present[$c->id] ?? null) : null,
            'fees_outstanding' => $can('fees.view') ? (float) ($fees[$c->id] ?? 0) : null,
            'fees_collected_month' => $can('fees.view') ? (float) ($collected[$c->id] ?? 0) : null,
        ])->values()->all();
    }
}
