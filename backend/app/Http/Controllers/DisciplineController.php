<?php

namespace App\Http\Controllers;

use App\Models\DisciplineCase;
use App\Models\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Discipline: incident reports against students, the action taken and case closure.
 * Cases belong to one campus; a student outside the active campus cannot be charged.
 */
class DisciplineController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function summary(): JsonResponse
    {
        $open = DisciplineCase::where('status', 'open');

        $bySeverity = DisciplineCase::where('status', 'open')
            ->selectRaw('severity, count(*) c')->groupBy('severity')->pluck('c', 'severity');

        $topRows = DisciplineCase::where('status', 'open')
            ->selectRaw('student_id, count(*) c')->groupBy('student_id')
            ->orderByDesc('c')->orderBy('student_id')->limit(5)->get();

        $students = Student::whereIn('id', $topRows->pluck('student_id')->all())
            ->with('schoolClass:id,name')
            ->get()->keyBy('id');

        return response()->json(['data' => [
            'open' => (clone $open)->count(),
            'closed' => DisciplineCase::where('status', 'closed')->count(),
            'closed_this_month' => DisciplineCase::where('status', 'closed')
                ->whereDate('updated_at', '>=', now()->startOfMonth())->count(),
            'major_open' => (int) ($bySeverity['major'] ?? 0),
            'by_severity' => [
                'minor' => (int) ($bySeverity['minor'] ?? 0),
                'moderate' => (int) ($bySeverity['moderate'] ?? 0),
                'major' => (int) ($bySeverity['major'] ?? 0),
            ],
            'by_category' => DisciplineCase::where('status', 'open')
                ->selectRaw('category, count(*) c')->groupBy('category')->pluck('c', 'category')
                ->map(fn ($c) => (int) $c),
            'top_students' => $topRows->map(fn ($r) => [
                'student_id' => (int) $r->student_id,
                'name' => $students->get($r->student_id)
                    ? trim($students->get($r->student_id)->first_name . ' ' . $students->get($r->student_id)->last_name)
                    : 'Removed student',
                'admission_no' => $students->get($r->student_id)?->admission_no,
                'class' => $students->get($r->student_id)?->schoolClass?->name,
                'open_cases' => (int) $r->c,
            ])->values(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', DisciplineCase::STATUSES)],
            'severity' => ['nullable', 'in:' . implode(',', DisciplineCase::SEVERITIES)],
            'category' => ['nullable', 'in:' . implode(',', DisciplineCase::CATEGORIES)],
            'action' => ['nullable', 'in:' . implode(',', DisciplineCase::ACTIONS)],
            'student_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        $rows = DisciplineCase::with([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'handler:id,name',
            'campus:id,name,code',
        ])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($data['student_id'] ?? null, fn ($q, $i) => $q->where('student_id', $i))
            ->when($data['class_id'] ?? null, fn ($q, $i) => $q->whereHas('student', fn ($s) => $s->where('class_id', $i)))
            ->when($data['from'] ?? null, fn ($q, $f) => $q->whereDate('incident_on', '>=', $f))
            ->when($data['to'] ?? null, fn ($q, $t) => $q->whereDate('incident_on', '<=', $t))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($w) => $w->where('description', 'like', "%$s%")
                    ->orWhereHas('student', fn ($st) => $st->where('first_name', 'like', "%$s%")
                        ->orWhere('last_name', 'like', "%$s%")->orWhere('admission_no', 'like', "%$s%"))
            ))
            ->orderByRaw("case severity when 'major' then 0 when 'moderate' then 1 else 2 end")
            ->latest('incident_on')->latest('id')
            ->paginate(min(100, (int) $request->input('per_page', 50)));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $student = $this->resolveStudent($data['student_id']);
        if (!$student) return $this->noStudent();

        if (($error = $this->checkSuspension($data['action'], $data['suspension_days']))) return $error;

        $case = DisciplineCase::create($data + [
            'organization_id' => $this->ctx->organizationId,
            'campus_id' => $student->campus_id ?: $this->ctx->campusId(),
            'status' => 'open',
            'handled_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($case->fresh())], 201);
    }

    public function update(Request $request, DisciplineCase $case): JsonResponse
    {
        if ($case->status === 'closed') {
            return response()->json(['message' => 'This case is closed. Its record is kept as it was decided.'], 422);
        }

        $data = $this->validated($request, updating: true);
        $action = $data['action'] ?? $case->action;
        if ($action !== 'suspension' && !array_key_exists('suspension_days', $data)) {
            $data['suspension_days'] = 0; // the count means nothing once the suspension is off the record
        }
        if (($error = $this->checkSuspension($action, $data['suspension_days'] ?? $case->suspension_days))) return $error;

        if (isset($data['student_id']) && $data['student_id'] != $case->student_id) {
            $student = $this->resolveStudent($data['student_id']);
            if (!$student) return $this->noStudent();
            $data['campus_id'] = $student->campus_id;
        }

        $case->update($data);
        return response()->json(['data' => $this->present($case->fresh())]);
    }

    /** Closing is a decision: it needs the action taken, the remarks and the officer who did it. */
    public function close(Request $request, DisciplineCase $case): JsonResponse
    {
        if ($case->status === 'closed') {
            return response()->json(['message' => 'This case is already closed, so it cannot be closed again.'], 422);
        }

        $data = $request->validate([
            'action' => ['required', 'in:' . implode(',', DisciplineCase::CLOSING_ACTIONS)],
            'remarks' => ['required', 'string', 'max:2000'],
            'suspension_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'parent_notified' => ['nullable', 'boolean'],
        ]);

        $days = (int) ($data['suspension_days'] ?? ($data['action'] === 'suspension' ? $case->suspension_days : 0));
        if (($error = $this->checkSuspension($data['action'], $days))) return $error;

        $case->update(array_merge($data, [
            'suspension_days' => $days,
            'parent_notified' => (bool) ($data['parent_notified'] ?? $case->parent_notified),
            'status' => 'closed',
            'handled_by' => $request->user()->id,
        ]));

        return response()->json(['data' => $this->present($case->fresh()), 'message' => 'Case closed.']);
    }

    // ------------------------------------------------------------------------ helpers

    private function validated(Request $request, bool $updating = false): array
    {
        $patch = $updating ? ['sometimes'] : [];
        $rule = fn (array $rules) => array_merge($patch, $rules);

        $data = $request->validate([
            'student_id' => $rule(['required', 'integer']),
            'incident_on' => $rule(['required', 'date']),
            'category' => $rule(['required', 'in:' . implode(',', DisciplineCase::CATEGORIES)]),
            'severity' => $rule(['required', 'in:' . implode(',', DisciplineCase::SEVERITIES)]),
            'description' => $rule(['required', 'string', 'max:2000']),
            'action' => $rule(['nullable', 'in:' . implode(',', DisciplineCase::ACTIONS)]),
            'suspension_days' => $rule(['nullable', 'integer', 'min:0', 'max:365']),
            'parent_notified' => $rule(['nullable', 'boolean']),
            'remarks' => $rule(['nullable', 'string', 'max:2000']),
        ]);

        // These columns are NOT NULL with defaults, so an omitted or null entry means the default.
        if (!$updating) {
            $data['action'] = $data['action'] ?? 'none';
            $data['suspension_days'] = (int) ($data['suspension_days'] ?? 0);
            $data['parent_notified'] = (bool) ($data['parent_notified'] ?? false);
        } else {
            foreach (['action' => 'none', 'suspension_days' => 0, 'parent_notified' => false] as $key => $default) {
                if (array_key_exists($key, $data) && $data[$key] === null) $data[$key] = $default;
            }
        }

        return $data;
    }

    /** Suspension days belong to a suspension and to nothing else. */
    private function checkSuspension(?string $action, mixed $days): ?JsonResponse
    {
        $days = (int) $days;
        if ($action === 'suspension' && $days < 1) {
            return response()->json(['message' => 'A suspension must say how many days the student is suspended for.'], 422);
        }
        if ($action !== 'suspension' && $days > 0) {
            return response()->json(['message' => 'Suspension days only apply when the action is a suspension — set them to zero.'], 422);
        }
        return null;
    }

    private function resolveStudent(int $studentId): ?Student
    {
        return Student::find($studentId); // tenant scope already restricts this to the active campus
    }

    private function noStudent(): JsonResponse
    {
        return response()->json(['message' => 'That student is not in your campus, so no case can be opened against them.'], 422);
    }

    private function present(DisciplineCase $case): DisciplineCase
    {
        return $case->load([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'handler:id,name',
            'campus:id,name,code',
        ]);
    }
}
