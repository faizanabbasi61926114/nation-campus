<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Section;
use App\Models\Student;
use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a class teacher opens on a phone: the classes they own, today's periods in
 * order, and how much of today's roll call is still blank.
 */
class TeacherController extends Controller
{
    /** GET /teacher/my-class */
    public function myClass(Request $request): JsonResponse
    {
        $me = $request->user();
        $today = now()->toDateString();
        $dayOfWeek = (int) now()->dayOfWeekIso;

        $sections = Section::with(['schoolClass:id,name', 'campus:id,name,code'])
            ->where('class_teacher_id', $me->id)
            ->orderBy('name')
            ->get(['id', 'name', 'class_id', 'campus_id', 'capacity']);

        $students = $sections->isEmpty() ? collect() : Student::whereIn('section_id', $sections->pluck('id'))
            ->where('status', 'active')->get(['id', 'section_id']);

        $roll = $this->rollCallToday($students->pluck('id'), $today);
        $sectionOf = $students->pluck('section_id', 'id');

        $perSection = $sections->map(function (Section $s) use ($students, $roll, $sectionOf) {
            $mine = $students->where('section_id', $s->id);
            $dayRows = $roll[0] ?? collect();
            $marked = $dayRows->filter(fn ($r) => $sectionOf->get($r->subject_id) === $s->id);
            $present = $marked->filter(fn ($r) => in_array($r->status, ['present', 'late'], true));
            return [
                'id' => $s->id,
                'name' => $s->name,
                'class' => $s->schoolClass?->name,
                'campus' => $s->campus?->only(['id', 'name', 'code']),
                'students' => $mine->count(),
                'attendance_marked_today' => $marked->count(),
                'attendance_present_today' => $present->count(),
                'attendance_complete' => $mine->isNotEmpty() && $marked->count() >= $mine->count(),
            ];
        });

        $slots = TimetableSlot::with(['section.schoolClass', 'subject:id,name'])
            ->where('teacher_id', $me->id)->where('day_of_week', $dayOfWeek)
            ->orderBy('start_time')->get();

        // The period number a roll call row is keyed on is the slot's position in that
        // section's day, which is what the desktop timetable grid shows.
        $ranks = $this->periodRanks($slots->pluck('section_id')->unique()->all() ?: [0], $dayOfWeek);

        $periods = $slots->map(function (TimetableSlot $slot) use ($ranks, $roll, $students, $sectionOf) {
            $periodNo = $ranks[$slot->id] ?? 1;
            $rows = ($roll[$periodNo] ?? collect())->filter(fn ($r) => $sectionOf->get($r->subject_id) === $slot->section_id);
            $total = $students->where('section_id', $slot->section_id)->count();
            return [
                'slot_id' => $slot->id,
                'period_no' => $periodNo,
                'section_id' => $slot->section_id,
                'section' => trim(($slot->section?->schoolClass?->name ?? '') . ' ' . ($slot->section?->name ?? '')),
                'subject' => $slot->subject?->name,
                'start_time' => substr((string) $slot->start_time, 0, 5),
                'end_time' => substr((string) $slot->end_time, 0, 5),
                'room' => $slot->room,
                'students' => $total,
                'attendance_marked' => $rows->count(),
                'attendance_complete' => $total > 0 && $rows->count() >= $total,
            ];
        });

        return response()->json(['data' => [
            'date' => $today,
            'teacher' => ['id' => $me->id, 'name' => $me->name],
            'sections' => $perSection,
            'periods' => $periods,
            'exams' => Exam::whereIn('status', ['scheduled', 'ongoing', 'completed'])
                ->orderBy('start_date')->limit(5)
                ->get(['id', 'name', 'type', 'start_date', 'end_date', 'status']),
        ]]);
    }

    /** @return array<int, Collection<int, object>> attendance rows keyed by period number */
    private function rollCallToday(Collection $studentIds, string $today): array
    {
        if ($studentIds->isEmpty()) return [];
        return DB::table('attendance_records')
            ->where('subject_type', 'student')->where('date', $today)
            ->whereIn('subject_id', $studentIds)
            ->get(['subject_id', 'period_no', 'status'])
            ->groupBy(fn ($r) => (int) $r->period_no)
            ->all();
    }

    /** timetable slot id -> its 1-based position in that section's day. */
    private function periodRanks(array $sectionIds, int $dayOfWeek): array
    {
        $rows = TimetableSlot::whereIn('section_id', $sectionIds)
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('start_time')->orderBy('id')
            ->get(['id', 'section_id']);

        // Written as a loop on purpose: flatMap/collapse runs array_merge, which
        // silently renumbers these integer slot-id keys into a plain list.
        $ranks = [];
        foreach ($rows->groupBy('section_id') as $day) {
            foreach ($day->values() as $index => $slot) {
                $ranks[$slot->id] = $index + 1;
            }
        }

        return $ranks;
    }
}
