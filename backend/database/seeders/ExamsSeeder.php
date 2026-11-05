<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\Campus;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExamsSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        if (!$lhr) return;
        $controller = User::firstWhere('email', 'exams.lhr@sunrise.edu.pk');

        $subjects = Subject::whereIn('code', ['ENG', 'URD', 'MTH', 'PHY', 'CHM'])->orderBy('id')->get();
        if ($subjects->count() < 5) return;

        $annual = Exam::firstOrCreate(
            ['campus_id' => $lhr->id, 'name' => 'First Terminal Examination 2026'],
            [
                'organization_id' => 1, 'type' => 'mid_term', 'start_date' => '2026-09-15', 'end_date' => '2026-09-25',
                'status' => 'published',
            ],
        );
        $monthly = Exam::firstOrCreate(
            ['campus_id' => $lhr->id, 'name' => 'October Monthly Test'],
            [
                'organization_id' => 1, 'type' => 'monthly', 'start_date' => '2026-10-20', 'end_date' => '2026-10-22',
                'status' => 'scheduled',
            ],
        );

        foreach ([$annual, $monthly] as $exam) {
            foreach ($subjects as $si => $subject) {
                ExamSubject::firstOrCreate(
                    ['exam_id' => $exam->id, 'subject_id' => $subject->id],
                    ['max_marks' => 100, 'pass_marks' => 40],
                );
            }
        }

        // Deterministic marks for the published exam so report cards and merit lists have data
        $rows = 0;
        foreach ($annual->subjects()->get() as $si => $es) {
            foreach (Student::where('campus_id', $lhr->id)->where('status', 'active')->orderBy('id')->get() as $i => $student) {
                $marks = max(25, min(98, 55 + (($i * 13 + $si * 29) % 40) - ($i % 7 === 3 ? 30 : 0)));
                ExamMark::firstOrCreate(
                    ['exam_id' => $annual->id, 'student_id' => $student->id, 'exam_subject_id' => $es->id],
                    ['organization_id' => 1, 'campus_id' => $lhr->id, 'marks_obtained' => $marks, 'entered_by' => $controller?->id],
                );
                $rows++;
            }
        }
        $this->command?->info("ExamsSeeder: $rows mark rows.");
    }
}
