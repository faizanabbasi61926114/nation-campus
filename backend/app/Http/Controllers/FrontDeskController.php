<?php

namespace App\Http\Controllers;

use App\Models\VisitorLog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FrontDeskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'open' => 'nullable|boolean',
        ]);

        $q = VisitorLog::with([
            'meetsUser:id,name',
            'meetsStudent:id,first_name,last_name,admission_no',
            'logger:id,name',
        ]);

        if (!empty($data['from']) || !empty($data['to'])) {
            $from = $data['from'] ?? null;
            $to = $data['to'] ?? null;
            $q->when($from, fn ($w) => $w->whereDate('in_at', '>=', $from))
              ->when($to, fn ($w) => $w->whereDate('in_at', '<=', $to));
        } elseif (!empty($data['date'])) {
            $q->whereDate('in_at', $data['date']);
        } else {
            $q->whereDate('in_at', now()->toDateString()); // default: today
        }

        if (!empty($data['open'])) $q->whereNull('out_at');

        return response()->json(['data' => $q->latest('in_at')->limit(400)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before logging a visitor.'], 422);

        $data = $this->validateVisitor($request, false);
        $data['in_at'] = $data['in_at'] ?? now()->toDateTimeString(); // in_at is required by the schema
        $this->guardTimes($data);

        $visitor = VisitorLog::create($data + [
            'organization_id' => $ctx->organizationId,
            'campus_id' => $ctx->campusId(),
            'logged_by' => $request->user()->id,
            'pass_no' => $this->nextPassNo($ctx->campusId(), $data['in_at']),
        ]);

        return response()->json(['data' => $visitor->load([
            'meetsUser:id,name', 'meetsStudent:id,first_name,last_name,admission_no', 'logger:id,name',
        ])], 201);
    }

    public function update(Request $request, VisitorLog $visitor): JsonResponse
    {
        $data = $this->validateVisitor($request, true);

        // Only enforce the future/out-before-in rules when the caller actually edits a time.
        if (array_key_exists('in_at', $data) || array_key_exists('out_at', $data)) {
            $inAt = $data['in_at'] ?? $visitor->in_at?->toDateTimeString();
            $outAt = $data['out_at'] ?? $visitor->out_at?->toDateTimeString();
            $this->guardTimes(['in_at' => $inAt, 'out_at' => $outAt]);
        }

        $visitor->update($data);
        return response()->json(['data' => $visitor->fresh([
            'meetsUser:id,name', 'meetsStudent:id,first_name,last_name,admission_no', 'logger:id,name',
        ])]);
    }

    public function checkOut(Request $request, VisitorLog $visitor): JsonResponse
    {
        if ($visitor->out_at) {
            return response()->json(['message' => 'This visitor has already checked out.'], 422);
        }
        $visitor->update(['out_at' => now()]);
        return response()->json(['data' => $visitor->fresh([
            'meetsUser:id,name', 'meetsStudent:id,first_name,last_name,admission_no', 'logger:id,name',
        ])]);
    }

    public function summary(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        $purposes = VisitorLog::whereDate('in_at', $today)
            ->selectRaw('purpose, count(*) c')
            ->groupBy('purpose')
            ->orderByDesc('c')
            ->limit(3)
            ->get(['purpose', 'c'])
            ->map(fn ($p) => ['purpose' => $p->purpose, 'count' => (int) $p->c]);

        return response()->json(['data' => [
            'today_in' => VisitorLog::whereDate('in_at', $today)->count(),
            'today_out' => VisitorLog::whereDate('out_at', $today)->count(),
            'still_inside' => VisitorLog::whereNull('out_at')->count(),
            'week_total' => VisitorLog::where('in_at', '>=', now()->startOfWeek())->count(),
            'top_purposes' => $purposes,
        ]]);
    }

    // ---- Helpers --------------------------------------------------------

    private function validateVisitor(Request $request, bool $partial): array
    {
        $userRule = $partial ? ['sometimes', 'nullable', 'exists:users,id'] : ['nullable', 'exists:users,id'];
        $studentRule = $partial ? ['sometimes', 'nullable', 'exists:students,id'] : ['nullable', 'exists:students,id'];
        $photoRule = $partial ? ['sometimes', Rule::in(VisitorLog::PHOTO_OPTIONS)] : ['nullable', Rule::in(VisitorLog::PHOTO_OPTIONS)];

        $p = $partial ? 'sometimes|' : '';
        return $request->validate([
            'name' => $p . 'required|string|max:120',
            'phone' => $p . 'nullable|string|max:24',
            'cnic' => $p . 'nullable|string|max:20',
            'from_organization' => $p . 'nullable|string|max:120',
            'purpose' => $p . 'required|string|max:190',
            'meets_user_id' => $userRule,
            'meets_student_id' => $studentRule,
            'in_at' => $p . 'nullable|date',
            'out_at' => $p . 'nullable|date',
            'gate' => $p . 'nullable|string|max:32',
            'photo_id_taken' => $photoRule,
        ]);
    }

    /**
     * Reject a visitor row whose in_at is in the future, or whose out_at precedes
     * in_at, with plain-English messages rather than a generic validation blob.
     */
    private function guardTimes(array $data): void
    {
        $inAt = !empty($data['in_at']) ? \Carbon\Carbon::parse($data['in_at']) : null;
        $outAt = !empty($data['out_at']) ? \Carbon\Carbon::parse($data['out_at']) : null;

        if ($inAt && $inAt->greaterThan(now()->addMinute())) {
            throw ValidationException::withMessages(['in_at' => 'The entry time cannot be in the future.']);
        }
        if ($inAt && $outAt && $outAt->lessThan($inAt)) {
            throw ValidationException::withMessages(['out_at' => 'The exit time cannot be before the entry time.']);
        }
    }

    /**
     * V-YYMMDD-NN where NN is this campus's next sequence for that day. Scoped to
     * the campus so two campuses logging on the same day never disturb each other.
     */
    private function nextPassNo(int $campusId, string $inAt): string
    {
        $prefix = 'V-' . \Carbon\Carbon::parse($inAt)->format('ymd') . '-';
        $max = VisitorLog::withoutGlobalScopes()
            ->where('campus_id', $campusId)
            ->where('pass_no', 'like', $prefix . '%')
            ->pluck('pass_no')
            ->map(fn ($p) => (int) substr($p, strlen($prefix)))
            ->max();

        return sprintf('%s%02d', $prefix, ((int) $max) + 1);
    }
}
