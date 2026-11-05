<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\GradingScale;
use App\Models\Setting;
use App\Models\Student;
use App\Support\Grading;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamController extends Controller
{
    public function index(): JsonResponse
    {
        $exams = Exam::withCount('subjects')->orderByDesc('start_date')->get();
        return response()->json(['data' => $exams]);
    }

    public function store(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before creating an exam.'], 422);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|string|max:30',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'subjects' => 'present|array',
            'subjects.*.subject_id' => 'required|exists:subjects,id',
            'subjects.*.max_marks' => 'nullable|integer|min:1|max:1000',
            'subjects.*.pass_marks' => 'nullable|integer|min:0|max:1000',
        ]);
        $exam = Exam::create([
            'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
            'name' => $data['name'], 'type' => $data['type'], 'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null, 'academic_year_id' => $data['academic_year_id'] ?? null,
            'status' => 'draft',
        ]);
        $this->syncSubjects($exam, $data['subjects']);
        return response()->json(['data' => $exam->load('subjects.subject')], 201);
    }

    public function update(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'type' => 'sometimes|required|string|max:30',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => ['sometimes', 'in:' . implode(',', Exam::STATUSES)],
            'subjects' => 'sometimes|array',
            'subjects.*.subject_id' => 'required_with:subjects|exists:subjects,id',
            'subjects.*.max_marks' => 'nullable|integer|min:1|max:1000',
            'subjects.*.pass_marks' => 'nullable|integer|min:0|max:1000',
        ]);
        if (isset($data['status']) && $data['status'] !== $exam->status) {
            if (!in_array($data['status'], Exam::TRANSITIONS[$exam->status] ?? [], true)) {
                return response()->json(['message' => "Cannot move exam from {$exam->status} to {$data['status']}."], 422);
            }
        }
        if (isset($data['subjects'])) $this->syncSubjects($exam, $data['subjects']);
        unset($data['subjects']);
        $exam->update($data);
        return response()->json(['data' => $exam->fresh()->load('subjects.subject')]);
    }

    public function show(Exam $exam): JsonResponse
    {
        return response()->json(['data' => $exam->load('subjects.subject')]);
    }

    public function roster(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate(['section_id' => 'required|exists:sections,id']);
        $students = Student::where('section_id', $data['section_id'])->where('status', 'active')
            ->orderBy('roll_no')->get(['id', 'roll_no', 'first_name', 'last_name']);
        $marks = ExamMark::where('exam_id', $exam->id)->whereIn('student_id', $students->pluck('id'))
            ->get()->groupBy('student_id')->map(fn ($g) => $g->keyBy('exam_subject_id'));
        return response()->json(['data' => [
            'exam' => $exam->load('subjects.subject'),
            'students' => $students->map(fn ($s) => array_merge($s->toArray(), ['marks' => $marks[$s->id] ?? collect()])),
        ]]);
    }

    public function enterMarks(Request $request, Exam $exam): JsonResponse
    {
        if (in_array($exam->status, ['published', 'archived'], true)) {
            return response()->json(['message' => 'Marks are locked once the exam is published.'], 422);
        }
        $data = $request->validate([
            'marks' => 'required|array|min:1',
            'marks.*.student_id' => 'required|integer',
            'marks.*.exam_subject_id' => 'required|integer',
            'marks.*.marks_obtained' => 'nullable|numeric|min:0',
            'marks.*.is_absent' => 'nullable|boolean',
        ]);
        $ctx = app(TenantContext::class);
        $subjects = ExamSubject::where('exam_id', $exam->id)->get()->keyBy('id');
        $allowedStudents = Student::whereIn('id', collect($data['marks'])->pluck('student_id'))->pluck('id');
        $enteredBy = $request->user()->id;
        $count = 0;

        DB::transaction(function () use ($data, $exam, $subjects, $allowedStudents, $ctx, $enteredBy, &$count) {
            foreach ($data['marks'] as $m) {
                $es = $subjects[$m['exam_subject_id']] ?? null;
                if (!$es) throw \Illuminate\Validation\ValidationException::withMessages([
                    'marks' => "Subject row {$m['exam_subject_id']} does not belong to this exam.",
                ]);
                if (!$allowedStudents->contains($m['student_id'])) throw \Illuminate\Validation\ValidationException::withMessages([
                    'marks' => "Student {$m['student_id']} is not in your campus.",
                ]);
                if (($m['marks_obtained'] ?? null) !== null && $m['marks_obtained'] > $es->max_marks) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'marks' => "Marks for subject {$es->subject_id} exceed the maximum of {$es->max_marks}.",
                    ]);
                }
                ExamMark::updateOrCreate(
                    ['exam_id' => $exam->id, 'student_id' => $m['student_id'], 'exam_subject_id' => $m['exam_subject_id']],
                    [
                        'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
                        'marks_obtained' => ($m['is_absent'] ?? false) ? null : $m['marks_obtained'],
                        'is_absent' => $m['is_absent'] ?? false,
                        'entered_by' => $enteredBy,
                    ],
                );
                $count++;
            }
        });
        return response()->json(['message' => "Saved $count mark row(s)."]);
    }

    public function reportCard(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate(['student_id' => 'required|exists:students,id']);
        $student = Student::findOrFail($data['student_id']);
        $subjects = ExamSubject::where('exam_id', $exam->id)->with('subject')->get();
        $marks = ExamMark::where('exam_id', $exam->id)->where('student_id', $student->id)->get()->keyBy('exam_subject_id');

        $rows = $subjects->map(function ($es) use ($marks, $exam) {
            $m = $marks[$es->id] ?? null;
            $obtained = $m && !$m->is_absent ? (float) $m->marks_obtained : 0;
            return [
                'exam_subject_id' => $es->id,
                'subject' => $es->subject->name,
                'max_marks' => $es->max_marks,
                'pass_marks' => $es->pass_marks,
                'marks_obtained' => $m && $m->is_absent ? null : ($m ? (float) $m->marks_obtained : null),
                'is_absent' => (bool) ($m?->is_absent),
                'passed' => $obtained >= $es->pass_marks,
                'result' => $exam->status === 'published' ? $this->gradeLabel($obtained, $es->max_marks) : null,
            ];
        });

        $totalMax = $rows->sum('max_marks');
        $total = $rows->sum(fn ($r) => $r['marks_obtained'] ?? 0);
        $pct = $totalMax > 0 ? round($total / $totalMax * 100, 2) : 0;
        $failed = $rows->where('passed', false)->count();

        return response()->json(['data' => [
            'exam' => $exam->only(['id', 'name', 'type', 'status', 'start_date']),
            'student' => $student->only(['id', 'first_name', 'last_name', 'roll_no', 'admission_no'])
                + ['section' => $student->section?->name, 'class' => $student->schoolClass?->name],
            'subjects' => $rows,
            'total_marks' => $total, 'max_marks' => $totalMax, 'percentage' => $pct,
            'grade' => $exam->status === 'published' ? $this->gradeLabel($total, $totalMax) : null,
            'result' => $exam->status === 'published' ? ($failed === 0 ? 'Pass' : 'Fail (' . $failed . ' subject(s))') : 'Awaiting publication',
            'published' => $exam->status === 'published',
        ]]);
    }

    public function meritList(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate(['section_id' => 'nullable|exists:sections,id']);
        $studentIds = $exam->marks()->distinct()->pluck('student_id');
        $q = Student::whereIn('id', $studentIds);
        if ($data['section_id'] ?? null) $q->where('section_id', $data['section_id']);
        $students = $q->get(['id', 'first_name', 'last_name', 'roll_no', 'section_id']);

        $subjects = ExamSubject::where('exam_id', $exam->id)->get()->keyBy('id');
        $marks = ExamMark::where('exam_id', $exam->id)->get()->groupBy('student_id');

        $ranked = $students->map(function ($s) use ($marks, $subjects) {
            $total = 0; $maxTotal = 0;
            foreach ($marks[$s->id] ?? collect() as $m) {
                $es = $subjects[$m->exam_subject_id] ?? null;
                if (!$es) continue;
                $total += $m->is_absent ? 0 : (float) $m->marks_obtained;
                $maxTotal += $es->max_marks;
            }
            return $s->only(['id', 'first_name', 'last_name', 'roll_no'])
                + ['total' => $total, 'out_of' => $maxTotal, 'percentage' => $maxTotal ? round($total / $maxTotal * 100, 2) : 0];
        })->sortByDesc('percentage')->values();

        $position = 1;
        $ranked = $ranked->map(function ($row, $i) use (&$position, $ranked) {
            if ($i > 0 && $row['percentage'] < $ranked[$i - 1]['percentage']) $position = $i + 1;
            $row['position'] = $position;
            return $row;
        });
        return response()->json(['data' => $ranked]);
    }

    public function gradingScales(): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $rows = GradingScale::where('organization_id', $ctx->organizationId)
            ->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $ctx->campusId()))
            ->orderByDesc('min_percent')->get();
        $campus = $rows->filter(fn ($s) => $s->campus_id !== null)->values();
        return response()->json(['data' => $campus->isNotEmpty() ? $campus : $rows->filter(fn ($s) => $s->campus_id === null)->values()]);
    }

    public function putGradingScale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => 'nullable|integer',
            'scales' => 'required|array|min:1',
            'scales.*.min_percent' => 'required|numeric|min:0|max:100',
            'scales.*.grade' => 'required|string|max:8',
            'scales.*.remark' => 'nullable|string|max:40',
        ]);
        $ctx = app(TenantContext::class);
        if ($data['campus_id'] && !in_array((int) $data['campus_id'], $request->user()->accessibleCampusIds(), true)) {
            return response()->json(['message' => 'Campus not accessible.'], 403);
        }
        DB::transaction(function () use ($data, $ctx) {
            GradingScale::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)
                ->when($data['campus_id'], fn ($q) => $q->where('campus_id', $data['campus_id']), fn ($q) => $q->whereNull('campus_id'))
                ->delete();
            foreach ($data['scales'] as $s) {
                GradingScale::create([
                    'organization_id' => $ctx->organizationId, 'campus_id' => $data['campus_id'] ?? null,
                    'min_percent' => $s['min_percent'], 'grade' => $s['grade'], 'remark' => $s['remark'] ?? null,
                ]);
            }
        });
        return response()->json(['message' => 'Grading scale saved.']);
    }

    private function syncSubjects(Exam $exam, array $subjects): void
    {
        foreach ($subjects as $s) {
            ExamSubject::updateOrCreate(
                ['exam_id' => $exam->id, 'subject_id' => $s['subject_id']],
                ['max_marks' => $s['max_marks'] ?? 100, 'pass_marks' => $s['pass_marks'] ?? 33],
            );
        }
        ExamSubject::where('exam_id', $exam->id)->whereNotIn('subject_id', collect($subjects)->pluck('subject_id')->all() ?: [0])->delete();
    }

    /** Campus-specific scale wins; falls back to the org default, then A-F bands. */
    private function gradeLabel(float $obtained, int|float $max): string
    {
        $ctx = app(TenantContext::class);
        return Grading::label($ctx->organizationId, $ctx->campusId(), $obtained, $max);
    }
}
