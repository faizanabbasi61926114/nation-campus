<?php

namespace App\Http\Controllers;

use App\Models\HostelAllotment;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hostel — blocks are single-gender, rooms have a fixed bed capacity, and a bed may hold
 * exactly one active allotment. Every rejection says the reason in plain English so the
 * warden never has to guess why an allotment failed.
 */
class HostelController extends Controller
{
    // ---------------------------------------------------------------- overview

    public function summary(): JsonResponse
    {
        $bedsTotal = (int) HostelRoom::sum('capacity');
        $occupied = HostelAllotment::active()->count();
        $today = now()->toDateString();

        $blocks = HostelBlock::withCount('rooms')
            ->withSum('rooms', 'capacity')
            ->orderBy('name')->get()
            ->map(function ($b) use ($today) {
                $beds = (int) ($b->rooms_sum_capacity ?? 0);
                $filled = HostelAllotment::active()->whereIn('hostel_room_id', $b->rooms()->pluck('id'))->count();
                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'gender' => $b->gender,
                    'is_active' => (bool) $b->is_active,
                    'rooms' => (int) $b->rooms_count,
                    'beds' => $beds,
                    'occupied' => $filled,
                    'free' => max(0, $beds - $filled),
                    'maintenance' => HostelRoom::where('hostel_block_id', $b->id)->where('status', 'maintenance')->count(),
                ];
            })
            ->values();

        return response()->json(['data' => [
            'blocks' => $blocks,
            'rooms' => HostelRoom::count(),
            'beds_total' => $bedsTotal,
            'beds_occupied' => $occupied,
            'beds_free' => max(0, $bedsTotal - $occupied),
            'occupancy_pct' => $bedsTotal > 0 ? round($occupied * 100 / $bedsTotal, 1) : 0.0,
            'monthly_revenue' => round((float) HostelRoom::where('status', 'available')->sum('monthly_fee'), 2),
            'vacated' => HostelAllotment::where('status', 'vacated')->count(),
            'hall_charge_included' => (bool) (Setting::getFor(app(TenantContext::class)->organizationId,
                app(TenantContext::class)->campusId(), 'hostel.include_mess', true)),
        ]]);
    }

    // ---------------------------------------------------------------- blocks

    public function blocks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gender' => 'nullable|in:boys,girls',
            'status' => 'nullable|in:active,inactive',
        ]);

        $blocks = HostelBlock::with('warden:id,name')
            ->withCount('rooms')
            ->withSum('rooms', 'capacity')
            ->when($data['gender'] ?? null, fn ($q, $g) => $q->where('gender', $g))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->orderBy('name')
            ->get();

        $blocks->transform(function ($b) {
            $b->beds = (int) ($b->rooms_sum_capacity ?? 0);
            $b->occupied = HostelAllotment::active()->whereIn('hostel_room_id', $b->rooms()->pluck('id'))->count();
            $b->free = max(0, $b->beds - $b->occupied);
            $b->setVisible(['id', 'campus_id', 'name', 'gender', 'warden_user_id', 'warden', 'is_active',
                'rooms_count', 'beds', 'occupied', 'free']);
            return $b;
        });

        return response()->json(['data' => $blocks]);
    }

    public function storeBlock(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before adding a hostel block.'], 422);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'gender' => ['required', Rule::in(HostelBlock::GENDERS)],
            'warden_user_id' => 'nullable|exists:users,id',
            'is_active' => 'nullable|boolean',
        ]);
        if (HostelBlock::whereRaw('lower(name) = ?', [strtolower($data['name'])])->exists()) {
            return response()->json(['message' => "A block named {$data['name']} already exists in this campus."], 422);
        }

        $block = HostelBlock::create($data + ['organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId()]);
        return response()->json(['data' => $block->load('warden:id,name')], 201);
    }

    public function updateBlock(Request $request, HostelBlock $block): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'gender' => ['sometimes', 'required', Rule::in(HostelBlock::GENDERS)],
            'warden_user_id' => 'nullable|exists:users,id',
            'is_active' => 'sometimes|boolean',
        ]);

        // Re-gendering a block while boys still hold beds in it would leave the house mixed.
        if (isset($data['gender']) && $data['gender'] !== $block->gender) {
            $live = HostelAllotment::active()->whereIn('hostel_room_id', $block->rooms()->pluck('id'))->count();
            if ($live) {
                return response()->json(['message' => "Change the gender only after vacating the $live active allotment(s) in {$block->name}."], 422);
            }
        }

        $block->update($data);
        return response()->json(['data' => $block->fresh('warden:id,name')]);
    }

    // ---------------------------------------------------------------- rooms

    public function rooms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'block_id' => 'nullable|integer',
            'status' => 'nullable|in:available,maintenance',
        ]);

        $rooms = HostelRoom::with(['block:id,name,gender,warden_user_id'])
            ->withCount(['liveAllotments'])
            ->with(['liveAllotments' => fn ($q) => $q->orderBy('bed_no')->with('student:id,first_name,last_name,roll_no')])
            ->when($data['block_id'] ?? null, fn ($q, $b) => $q->where('hostel_block_id', $b))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('hostel_block_id')->orderBy('number')
            ->get();

        $rooms->transform(function ($r) {
            $r->occupied = (int) $r->live_allotments_count;
            $r->free_beds = max(0, (int) $r->capacity - $r->occupied);
            $r->beds = collect(range(1, (int) $r->capacity))->map(function ($bed) use ($r) {
                $held = $r->liveAllotments->firstWhere('bed_no', $bed);
                return [
                    'bed_no' => $bed,
                    'taken' => (bool) $held,
                    'allotment_id' => $held?->id,
                    'student_id' => $held?->student_id,
                    'student' => $held ? $held->student_name : null,
                    'from_date' => $held?->from_date?->toDateString(),
                ];
            })->values();
            $r->setVisible(['id', 'campus_id', 'hostel_block_id', 'block', 'number', 'floor', 'capacity',
                'monthly_fee', 'status', 'occupied', 'free_beds', 'beds', 'allotments']);
            return $r;
        });

        return response()->json(['data' => $rooms]);
    }

    public function storeRoom(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before adding a room.'], 422);

        $data = $request->validate([
            'hostel_block_id' => 'required|integer',
            'number' => 'required|string|max:16',
            'floor' => 'nullable|string|max:8',
            'capacity' => 'required|integer|min:1|max:40',
            'monthly_fee' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(HostelRoom::STATUSES)],
        ]);
        $block = HostelBlock::find($data['hostel_block_id']);
        if (!$block) return response()->json(['message' => 'Hostel block not found in your campus.'], 422);
        if (HostelRoom::where('hostel_block_id', $block->id)->whereRaw('lower(number) = ?', [strtolower($data['number'])])->exists()) {
            return response()->json(['message' => "Room {$data['number']} already exists in {$block->name}."], 422);
        }

        $room = HostelRoom::create($data + [
            'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
            'monthly_fee' => $data['monthly_fee'] ?? (float) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'hostel.monthly_fee', 0)),
            'status' => $data['status'] ?? 'available',
        ]);
        return response()->json(['data' => $room->load('block:id,name,gender')], 201);
    }

    public function updateRoom(Request $request, HostelRoom $room): JsonResponse
    {
        $data = $request->validate([
            'number' => 'sometimes|required|string|max:16',
            'floor' => 'nullable|string|max:8',
            'capacity' => 'sometimes|required|integer|min:1|max:40',
            'monthly_fee' => 'nullable|numeric|min:0',
            'status' => ['sometimes', 'required', Rule::in(HostelRoom::STATUSES)],
        ]);

        if (isset($data['capacity'])) {
            $occupied = $room->liveAllotments()->count();
            if ((int) $data['capacity'] < $occupied) {
                return response()->json(['message' => "Room {$room->number} has $occupied bed(s) allotted; the capacity cannot drop below that."], 422);
            }
        }
        // A full room being sent for maintenance must be emptied first.
        if (($data['status'] ?? null) === 'maintenance' && $room->liveAllotments()->count()) {
            return response()->json(['message' => 'Vacate the beds in this room before sending it for maintenance.'], 422);
        }

        $room->update($data);
        return response()->json(['data' => $room->fresh('block:id,name,gender')]);
    }

    // ---------------------------------------------------------------- allotments

    public function allotments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:active,vacated',
            'student_id' => 'nullable|integer',
            'block_id' => 'nullable|integer',
        ]);

        $rows = HostelAllotment::with([
            'student:id,first_name,last_name,roll_no,gender',
            'room:id,number,capacity,monthly_fee,hostel_block_id',
            'room.block:id,name,gender',
            'approver:id,name',
        ])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['student_id'] ?? null, fn ($q, $i) => $q->where('student_id', $i))
            ->when($data['block_id'] ?? null, fn ($q, $b) => $q->whereIn('hostel_room_id', HostelRoom::where('hostel_block_id', $b)->pluck('id')))
            ->orderByRaw("status = 'active' desc")
            ->latest('from_date')
            ->limit(300)
            ->get();

        $rows->transform(fn ($a) => $a->append('student_name')->setVisible([
            'id', 'campus_id', 'student_id', 'student', 'student_name', 'hostel_room_id', 'room', 'bed_no',
            'from_date', 'to_date', 'status', 'notes', 'approved_by', 'approver',
        ]));

        return response()->json(['data' => $rows]);
    }

    public function allot(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'student_id' => 'required|integer',
            'hostel_room_id' => 'required|integer',
            'bed_no' => 'nullable|integer|min:1',
            'from_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $student = Student::find($data['student_id']);
        if (!$student) return response()->json(['message' => 'Student does not exist in your campus.'], 422);

        $room = HostelRoom::with('block')->find($data['hostel_room_id']);
        if (!$room) return response()->json(['message' => 'Room not found in your campus.'], 422);
        if (!$room->block?->is_active) return response()->json(['message' => 'Hostel block is inactive.'], 422);
        if ($room->status === 'maintenance') {
            return response()->json(['message' => "Room {$room->number} is under maintenance."], 422);
        }

        // The reader-first checks come before the room's own rules, so a student who is
        // already housed hears that rather than a gender complaint about the wrong house.
        $held = HostelAllotment::active()->where('student_id', $student->id)->first();
        if ($held) {
            return response()->json([
                'message' => $student->first_name . ' already holds bed ' . $held->bed_no . ' in '
                    . ($held->room?->block?->name ?? 'another block') . '. Vacate that bed first.',
            ], 422);
        }

        $gender = strtolower((string) $student->gender);
        if (!$room->block->acceptsGender($gender)) {
            return response()->json([
                'message' => $student->first_name . ' is ' . $gender . ', but ' . $room->block->name . ' is a ' . $room->block->gender . ' hostel.',
            ], 422);
        }

        $occupied = $room->liveAllotments()->count();
        if ($occupied >= (int) $room->capacity) {
            return response()->json(['message' => "Room {$room->number} is full ($occupied of {$room->capacity} beds allotted)."], 422);
        }

        $taken = $room->liveAllotments()->pluck('bed_no')->map(fn ($b) => (int) $b)->all();
        if ($data['bed_no'] ?? null) {
            $bed = (int) $data['bed_no'];
            if ($bed > (int) $room->capacity) {
                return response()->json(['message' => "Room {$room->number} has only {$room->capacity} bed(s)."], 422);
            }
            if (in_array($bed, $taken, true)) {
                return response()->json(['message' => "Bed $bed in room {$room->number} is already allotted."], 422);
            }
        } else {
            $bed = 1;
            while (in_array($bed, $taken, true)) $bed++;
        }

        $allotment = HostelAllotment::create([
            'organization_id' => $ctx->organizationId,
            'campus_id' => $ctx->campusId() ?: $room->campus_id,
            'student_id' => $student->id,
            'hostel_room_id' => $room->id,
            'bed_no' => $bed,
            'from_date' => $data['from_date'] ?? now()->toDateString(),
            'status' => 'active',
            'approved_by' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => $student->first_name . ' allotted bed ' . $bed . ' in room ' . $room->number . '.',
            'data' => $allotment->load(['student:id,first_name,last_name,roll_no', 'room:id,number,capacity,hostel_block_id', 'room.block:id,name,gender'])
                ->append('student_name')->setVisible(['id', 'student_id', 'student', 'student_name', 'hostel_room_id', 'room', 'bed_no', 'from_date', 'status']),
        ], 201);
    }

    public function vacate(Request $request, HostelAllotment $allotment): JsonResponse
    {
        $data = $request->validate([
            'to_date' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:500',
        ]);
        if ($allotment->status === 'vacated') {
            return response()->json(['message' => 'This bed is already vacated.'], 422);
        }

        $allotment->update([
            'status' => 'vacated',
            'to_date' => $data['to_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? $allotment->notes,
        ]);

        return response()->json([
            'message' => 'Bed ' . $allotment->bed_no . ' in room ' . ($allotment->room?->number ?? '') . ' vacated.',
            'data' => $allotment->fresh(['student:id,first_name,last_name,roll_no', 'room:id,number,capacity']),
        ]);
    }
}
