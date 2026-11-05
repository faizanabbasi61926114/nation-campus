<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * School calendar: holidays, closures, exams, PTMs, meetings and vacations.
 * A null campus_id means the event runs across the whole organization.
 */
class CalendarController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    /** Events whose range overlaps ?from&to (the current month when neither is given). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', 'in:' . implode(',', CalendarEvent::TYPES)],
            'audience' => ['nullable', 'in:' . implode(',', CalendarEvent::AUDIENCES)],
        ]);

        [$from, $to] = $this->range($data['from'] ?? null, $data['to'] ?? null);
        if ($from->gt($to)) {
            return response()->json(['message' => 'The end date cannot fall before the start date.'], 422);
        }

        $rows = CalendarEvent::with('campus:id,name,code')
            ->between($from->toDateString(), $to->toDateString())
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['audience'] ?? null, fn ($q, $a) => $q->where('audience', $a))
            ->orderBy('start_date')->orderBy('end_date')->orderBy('id')
            ->limit(500)->get();

        return response()->json(['data' => $rows, 'range' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'campus_id' => $this->ctx->campusId(),
        ]]);
    }

    public function summary(): JsonResponse
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $weekEnd = now()->copy()->addDays(7);

        $inMonth = CalendarEvent::between($monthStart->toDateString(), $monthEnd->toDateString());
        $upcoming = CalendarEvent::between($today, $weekEnd->toDateString())
            ->orderBy('start_date')->limit(10)->get();

        // Only rows that actually belong to this campus keep it shut today; a closure at
        // another campus must not switch the attendance screen off.
        $todayRows = CalendarEvent::query()
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->get()
            ->filter(fn (CalendarEvent $e) => $this->ctx->scoped($e->campus_id) && $e->affects_attendance)
            ->values();

        return response()->json(['data' => [
            'month' => now()->format('F Y'),
            'this_month' => (clone $inMonth)->count(),
            'holidays_this_month' => (clone $inMonth)->whereIn('type', CalendarEvent::ATTENDANCE_TYPES)->count(),
            'upcoming' => $upcoming,
            'today' => $today,
            'today_is_holiday' => $todayRows->isNotEmpty(),
            'today_events' => $todayRows->map(fn (CalendarEvent $e) => [
                'id' => $e->id, 'title' => $e->title, 'type' => $e->type,
                'is_campus_wide' => $e->is_campus_wide,
            ])->values(),
            'next_event' => $upcoming->first(fn (CalendarEvent $e) => $e->start_date->gt(now()->startOfDay())),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if (($err = $this->checkRange($data['start_date'], $data['end_date'] ?? $data['start_date']))) return $err;

        $event = CalendarEvent::create($this->attributes($request, $data) + [
            'organization_id' => $this->ctx->organizationId,
        ]);

        return response()->json(['data' => $event->fresh()->load('campus:id,name,code')], 201);
    }

    public function update(Request $request, CalendarEvent $event): JsonResponse
    {
        $data = $this->validated($request, updating: true);
        $start = $data['start_date'] ?? $event->start_date->toDateString();
        $end = $data['end_date'] ?? $event->end_date->toDateString();
        if (($err = $this->checkRange($start, $end))) return $err;

        $attributes = $data;
        if (array_key_exists('campus_id', $data)) {
            $attributes['campus_id'] = $data['campus_id'] === null ? $this->ctx->campusId() : $this->assertCampus((int) $data['campus_id']);
        }
        $event->update($attributes);

        return response()->json(['data' => $event->fresh()->load('campus:id,name,code')]);
    }

    public function destroy(CalendarEvent $event): JsonResponse
    {
        $event->delete();
        return response()->json(['message' => 'Event removed from the calendar.']);
    }

    // ------------------------------------------------------------------------ helpers

    /** @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon} */
    private function range(?string $from, ?string $to): array
    {
        $start = $from ? \Carbon\Carbon::parse($from)->startOfDay() : now()->startOfMonth();
        $end = $to ? \Carbon\Carbon::parse($to)->startOfDay() : now()->endOfMonth();
        // A one-ended query still deserves a sensible window: the rest of that month.
        if ($from && !$to) $end = $start->copy()->endOfMonth();
        if ($to && !$from) $start = $end->copy()->startOfMonth();
        return [$start, $end];
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $patch = $updating ? ['sometimes'] : [];
        $rule = fn (array $rules) => array_merge($patch, $rules);

        return $request->validate([
            'title' => $rule(['required', 'string', 'max:180']),
            'type' => $rule(['required', 'in:' . implode(',', CalendarEvent::TYPES)]),
            'start_date' => $rule(['required', 'date']),
            'end_date' => $rule(['nullable', 'date']),
            'description' => $rule(['nullable', 'string', 'max:2000']),
            'audience' => $rule(['nullable', 'in:' . implode(',', CalendarEvent::AUDIENCES)]),
            'affects_attendance' => $rule(['nullable', 'boolean']),
            'campus_id' => $rule(['nullable', 'integer']),
        ]);
    }

    /** Multi-day ranges are inclusive; an inverted one is always a mistake. */
    private function checkRange(string $start, ?string $end): ?JsonResponse
    {
        if ($end && \Carbon\Carbon::parse($end)->lt(\Carbon\Carbon::parse($start))) {
            return response()->json(['message' => 'The end date cannot fall before the start date.'], 422);
        }
        return null;
    }

    private function attributes(Request $request, array $data): array
    {
        $type = $data['type'];
        $start = $data['start_date'];
        $end = $data['end_date'] ?? null;

        // A holiday or closure keeps the campus shut unless the editor says otherwise.
        $attendance = array_key_exists('affects_attendance', $request->all())
            ? (bool) $data['affects_attendance']
            : in_array($type, CalendarEvent::ATTENDANCE_TYPES, true);

        return [
            'campus_id' => !empty($data['campus_id'])
                ? $this->assertCampus((int) $data['campus_id'])
                : $this->ctx->campusId(),
            'title' => $data['title'],
            'type' => $type,
            'start_date' => $start,
            'end_date' => $end ?: $start,
            'description' => $data['description'] ?? null,
            'audience' => $data['audience'] ?? 'all',
            'affects_attendance' => $attendance,
        ];
    }

    private function assertCampus(int $campusId): int
    {
        abort_unless(in_array($campusId, $this->ctx->user?->accessibleCampusIds() ?? [], true), 403, 'That campus is outside your assignment.');
        return $campusId;
    }
}
