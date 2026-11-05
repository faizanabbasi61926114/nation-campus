<?php

namespace App\Http\Controllers;

use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = TimetableSlot::with(['section.schoolClass', 'subject', 'teacher:id,name']);
        if ($request->filled('section_id')) $q->where('section_id', $request->integer('section_id'));
        if ($request->filled('day_of_week')) $q->where('day_of_week', $request->integer('day_of_week'));
        if ($request->filled('teacher_id')) $q->where('teacher_id', $request->integer('teacher_id'));
        return response()->json(['data' => $q->orderBy('day_of_week')->orderBy('start_time')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'nullable|exists:users,id',
            'day_of_week' => 'required|integer|min:1|max:7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'room' => 'nullable|string|max:32',
        ]);
        $clash = TimetableSlot::where('section_id', $data['section_id'])
            ->where('day_of_week', $data['day_of_week'])
            ->where(fn ($q) => $q->where('start_time', '<', $data['end_time'])->where('end_time', '>', $data['start_time']))
            ->exists();
        if ($clash) return response()->json(['message' => 'Period clashes with an existing slot for this section.'], 422);
        $teacherClash = ($data['teacher_id'] ?? null) && TimetableSlot::where('teacher_id', $data['teacher_id'])
            ->where('day_of_week', $data['day_of_week'])
            ->where(fn ($q) => $q->where('start_time', '<', $data['end_time'])->where('end_time', '>', $data['start_time']))
            ->exists();
        if ($teacherClash) return response()->json(['message' => 'Teacher is already booked for this period.'], 422);
        $slot = TimetableSlot::create($data);
        return response()->json(['data' => $slot->load(['section', 'subject', 'teacher'])], 201);
    }

    public function update(Request $request, TimetableSlot $slot): JsonResponse
    {
        $data = $request->validate([
            'section_id' => ['sometimes', 'required', 'exists:sections,id'],
            'subject_id' => ['sometimes', 'required', 'exists:subjects,id'],
            'teacher_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'day_of_week' => ['sometimes', 'required', 'integer', 'min:1', 'max:7'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
            'room' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        $candidate = array_merge($slot->only([
            'section_id', 'subject_id', 'teacher_id', 'day_of_week', 'start_time', 'end_time', 'room',
        ]), $data);
        if ($candidate['end_time'] <= substr((string) $candidate['start_time'], 0, 5)) {
            return response()->json(['message' => 'The end time must be later than the start time.'], 422);
        }

        $clash = TimetableSlot::where('id', '!=', $slot->id)
            ->where('section_id', $candidate['section_id'])
            ->where('day_of_week', $candidate['day_of_week'])
            ->where(fn ($q) => $q->where('start_time', '<', $candidate['end_time'])->where('end_time', '>', $candidate['start_time']))
            ->exists();
        if ($clash) return response()->json(['message' => 'Period clashes with an existing slot for this section.'], 422);

        $teacherClash = ($candidate['teacher_id'] ?? null) && TimetableSlot::where('id', '!=', $slot->id)
            ->where('teacher_id', $candidate['teacher_id'])
            ->where('day_of_week', $candidate['day_of_week'])
            ->where(fn ($q) => $q->where('start_time', '<', $candidate['end_time'])->where('end_time', '>', $candidate['start_time']))
            ->exists();
        if ($teacherClash) return response()->json(['message' => 'Teacher is already booked for this period.'], 422);

        $slot->update($data);
        return response()->json(['data' => $slot->fresh(['section.schoolClass', 'subject', 'teacher'])]);
    }

    public function destroy(TimetableSlot $slot): JsonResponse
    {
        $slot->delete();
        return response()->json(['message' => 'Slot removed']);
    }
}
