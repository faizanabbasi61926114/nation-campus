<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentEntry;
use App\Services\GuardianNotifier;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The student gate log. Reception taps a child In or Out; each tap is one row, and
 * the first arrival and first departure of a day alert the family in-app.
 */
class StudentEntryController extends Controller
{
    public function __construct(private GuardianNotifier $notifier)
    {
    }

    /** GET /entries — one day by default, newest tap first. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'direction' => 'nullable|in:in,out',
            'student_id' => 'nullable|integer',
            'q' => 'nullable|string|max:60',
        ]);

        $q = StudentEntry::with(['student.section.schoolClass', 'student.campus', 'recorder:id,name']);

        if (!empty($data['from']) || !empty($data['to'])) {
            $q->when($data['from'] ?? null, fn ($w) => $w->whereDate('at', '>=', $data['from']))
              ->when($data['to'] ?? null, fn ($w) => $w->whereDate('at', '<=', $data['to']));
        } else {
            $q->whereDate('at', $data['date'] ?? now()->toDateString());
        }

        $q->when($data['direction'] ?? null, fn ($w) => $w->where('direction', $data['direction']))
          ->when($data['student_id'] ?? null, fn ($w) => $w->where('student_id', $data['student_id']));

        if (!empty($data['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $data['q']) . '%';
            $q->whereHas('student', function (Builder $w) use ($like) {
                $w->where(fn (Builder $n) => $n->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('roll_no', 'like', $like)
                    ->orWhere('admission_no', 'like', $like));
            });
        }

        // Taps taken in the same second still have to read newest-first.
        return response()->json(['data' => $this->rows($q->orderByDesc('at')->orderByDesc('id')->limit(400)->get())]);
    }

    /** POST /entries — record one gate tap and notify the family if it is the day's first. */
    public function store(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) {
            return response()->json(['message' => 'Select a campus before recording a gate entry.'], 422);
        }

        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'direction' => ['required', 'in:' . implode(',', StudentEntry::DIRECTIONS)],
            'at' => 'nullable|date',
            'gate' => 'nullable|string|max:40',
            'remarks' => 'nullable|string|max:190',
        ]);

        $student = Student::find($data['student_id']);
        if (!$student) return response()->json(['message' => 'That student is not in your campus.'], 422);
        if ($student->status !== 'active') {
            throw ValidationException::withMessages(['student_id' => "{$student->first_name} is not enrolled as an active student."]);
        }

        $at = \Carbon\Carbon::parse($data['at'] ?? now()->toDateTimeString());
        if ($at->greaterThan(now()->addMinute())) {
            throw ValidationException::withMessages(['at' => 'The entry time cannot be in the future.']);
        }

        $entry = StudentEntry::create([
            'organization_id' => $ctx->organizationId,
            'campus_id' => $ctx->campusId(),
            'student_id' => $student->id,
            'direction' => $data['direction'],
            'at' => $at,
            'gate' => $data['gate'] ?? null,
            'source' => 'front_desk',
            'remarks' => $data['remarks'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        $notified = $this->notifier->entry($entry);

        return response()->json([
            'data' => $this->rows(collect([$entry]))[0],
            'message' => $notified
                ? $this->notifyVerb($entry) . ' — family alerted.'
                : $this->notifyVerb($entry) . ' — recorded.',
        ], 201);
    }

    /** GET /entries/summary — the four numbers the gate desk and the campus dashboard show. */
    public function summary(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $rows = StudentEntry::with(['student.section.schoolClass', 'student.campus', 'recorder:id,name'])
            ->onDate($today)->orderBy('at')->orderBy('id')->get();
        $lastPerStudent = $rows->groupBy('student_id')->map->last();

        $expected = Student::where('status', 'active')->count();

        return response()->json(['data' => [
            'date' => $today,
            'arrivals' => $rows->where('direction', 'in')->count(),
            'departures' => $rows->where('direction', 'out')->count(),
            'inside' => $lastPerStudent->where('direction', 'in')->count(),
            'expected' => $expected,
            'untapped' => max(0, $expected - $lastPerStudent->count()),
            'families_alerted' => $rows->whereNotNull('parent_notified_at')->count(),
            'by_gate' => $rows->groupBy(fn (StudentEntry $e) => $e->gate ?: 'Unspecified')
                ->map(fn (Collection $g) => $g->count())->sortByDesc(fn ($c) => $c)->take(4)
                ->map(fn ($c, $g) => ['gate' => $g, 'taps' => $c])->values(),
            // Stable sort: ordering by id first keeps taps from the same second newest-first.
            'recent' => $this->rows($rows->sortByDesc('id')->sortByDesc('at')->take(8)->values()),
        ]]);
    }

    // ---- helpers -----------------------------------------------------------------

    /**
     * Flat rows for both the web table and the phone screen: names resolved from the
     * student even when that student's class or campus is outside the reader's scope.
     */
    private function rows(Collection $entries): array
    {
        return $entries->map(function (StudentEntry $e) {
            $s = $e->student;

            return [
                'id' => $e->id,
                'student_id' => $e->student_id,
                'name' => $s ? trim("$s->first_name $s->last_name") : "#{$e->student_id}",
                'roll_no' => $s?->roll_no,
                'class' => trim(($s?->schoolClass?->name ?? '') . ' ' . ($s?->section?->name ?? '')) ?: null,
                'campus' => $s?->campus?->code,
                'direction' => $e->direction,
                'at' => $e->at->format('Y-m-d H:i'),
                'time' => $e->at->isoFormat('h:mm A'),
                'day' => $e->at->toDateString(),
                'gate' => $e->gate,
                'source' => $e->source,
                'remarks' => $e->remarks,
                'notified' => (bool) $e->parent_notified_at,
                'recorded_by' => $e->recorder?->name,
            ];
        })->all();
    }

    private function notifyVerb(StudentEntry $entry): string
    {
        $name = $entry->student ? trim("{$entry->student->first_name} {$entry->student->last_name}") : 'Entry';

        return "$name " . StudentEntry::verb($entry->direction);
    }
}
