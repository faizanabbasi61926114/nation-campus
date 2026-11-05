<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\CalendarEvent;
use Illuminate\Database\Seeder;

/**
 * Sample school calendar. Dates are anchored to the day the seed runs so the grid always
 * has a past event, something today and something upcoming. A null campus is deliberate:
 * it marks an event that every campus of the organization shares.
 */
class CalendarSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $isb = Campus::firstWhere('code', 'ISB');
        $psh = Campus::firstWhere('code', 'PSH');

        $events = [
            [
                'campus_id' => null, 'title' => 'Mid-term examinations, Grades 9 to 12', 'type' => 'exam',
                'offset' => 9, 'length' => 8, 'audience' => 'students', 'attendance' => false,
                'description' => 'Date sheets are on the notice board of every campus. Practicals run the two days before each written paper.',
            ],
            [
                'campus_id' => null, 'title' => 'Eid Milad un-Nabi', 'type' => 'holiday',
                'offset' => 21, 'length' => 2, 'audience' => 'all',
                'description' => 'Schools are closed for the whole organization. Classes resume on the next working day.',
            ],
            [
                'campus_id' => null, 'title' => 'Autumn vacation', 'type' => 'vacation',
                'offset' => 45, 'length' => 10, 'audience' => 'all', 'attendance' => false,
                'description' => 'Winter term reopens with the attendance register re-set.',
            ],
            [
                'campus_id' => $lhr?->id, 'title' => 'Founders Day sports gala', 'type' => 'event',
                'offset' => 5, 'length' => 1, 'audience' => 'all', 'attendance' => false,
                'description' => 'House competitions from 8am. Guardians are invited for the prize distribution at 4pm.',
            ],
            [
                'campus_id' => $lhr?->id, 'title' => 'Parent-teacher meeting, Grade 9 and 10', 'type' => 'ptm',
                'offset' => 18, 'length' => 1, 'audience' => 'all', 'attendance' => false,
                'description' => 'Fifteen-minute slots against the progress slip issued with the date sheet.',
            ],
            [
                'campus_id' => $lhr?->id, 'title' => 'Water tanker maintenance', 'type' => 'closure',
                'offset' => -2, 'length' => 1, 'audience' => 'all',
                'description' => 'The campus stayed shut for the day; online classes ran on the normal timetable.',
            ],
            [
                'campus_id' => $isb?->id, 'title' => 'Faculty development workshop', 'type' => 'meeting',
                'offset' => 3, 'length' => 1, 'audience' => 'faculty', 'attendance' => false,
                'description' => 'Teachers only. Sections are supervised by the class teachers on duty.',
            ],
            [
                'campus_id' => $psh?->id, 'title' => 'BISE registration last date', 'type' => 'event',
                'offset' => 7, 'length' => 1, 'audience' => 'students', 'attendance' => false,
                'description' => 'Matric candidates must submit the form and photographs to the office before 2pm.',
            ],
        ];

        foreach ($events as $e) {
            $start = now()->addDays($e['offset'])->startOfDay();
            $end = $start->copy()->addDays(max(0, $e['length'] - 1));

            // Attendance-affecting by default for holidays and closures, exactly as the API does it.
            $affectsAttendance = $e['attendance'] ?? in_array($e['type'], CalendarEvent::ATTENDANCE_TYPES, true);

            CalendarEvent::firstOrCreate(
                ['organization_id' => 1, 'title' => $e['title'], 'start_date' => $start->toDateString()],
                [
                    'campus_id' => $e['campus_id'],
                    'type' => $e['type'],
                    'end_date' => $end->toDateString(),
                    'description' => $e['description'],
                    'audience' => $e['audience'],
                    'affects_attendance' => $affectsAttendance,
                ],
            );
        }
    }
}
