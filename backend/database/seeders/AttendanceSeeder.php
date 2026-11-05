<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Campus;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        if (!$lhr) return;

        $statuses = ['present', 'present', 'present', 'present', 'present', 'present', 'late', 'absent', 'leave', 'excused'];
        $asOf = now()->endOfMonth()->min(now());

        // Full-day student attendance for October 2026 (Mon-Fri, up to today)
        $days = [];
        for ($d = 1; $d <= (int) $asOf->format('d'); $d++) {
            $date = $asOf->copy()->startOfMonth()->addDays($d - 1);
            if (!$date->isWeekend()) {
                $days[] = $date->toDateString();
            }
        }

        $students = Student::where('campus_id', $lhr->id)->where('status', 'active')->orderBy('id')->get();
        $teacher = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk');

        foreach ($students as $si => $student) {
            foreach ($days as $di => $date) {
                // One demo student kept below the 75% warning threshold
                $status = $si === 0 && $di % 3 !== 2 ? 'absent'
                    : $statuses[($si * 7 + $di * 3) % count($statuses)];
                AttendanceRecord::updateOrCreate(
                    ['subject_type' => 'student', 'subject_id' => $student->id, 'date' => $date, 'period_no' => 0],
                    ['organization_id' => 1, 'campus_id' => $lhr->id, 'status' => $status, 'marked_by' => $teacher?->id],
                );
            }
        }

        // Staff attendance for campus users
        foreach (User::where('campus_id', $lhr->id)->where('is_active', true)->get() as $ui => $user) {
            foreach ($days as $di => $date) {
                AttendanceRecord::updateOrCreate(
                    ['subject_type' => 'staff', 'subject_id' => $user->id, 'date' => $date, 'period_no' => 0],
                    ['organization_id' => 1, 'campus_id' => $lhr->id, 'status' => $statuses[($ui * 5 + $di) % count($statuses)], 'marked_by' => $teacher?->id],
                );
            }
        }
    }
}
