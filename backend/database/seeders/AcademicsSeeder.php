<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\Campus;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Database\Seeder;

class AcademicsSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');

        $year = AcademicYear::firstOrCreate(
            ['organization_id' => 1, 'campus_id' => null, 'name' => '2026-2027'],
            ['start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'is_current' => true],
        );

        $subjects = collect([
            ['name' => 'English', 'code' => 'ENG', 'group' => 'Compulsory'],
            ['name' => 'Urdu', 'code' => 'URD', 'group' => 'Compulsory'],
            ['name' => 'Mathematics', 'code' => 'MTH', 'group' => 'Science'],
            ['name' => 'Physics', 'code' => 'PHY', 'group' => 'Science'],
            ['name' => 'Chemistry', 'code' => 'CHM', 'group' => 'Science'],
            ['name' => 'Biology', 'code' => 'BIO', 'group' => 'Science'],
            ['name' => 'Computer Science', 'code' => 'CS', 'group' => 'Elective'],
            ['name' => 'Islam Studies', 'code' => 'IST', 'group' => 'Compulsory'],
            ['name' => 'Pakistan Studies', 'code' => 'PKS', 'group' => 'Compulsory'],
        ])->map(fn ($s) => Subject::firstOrCreate(['organization_id' => 1, 'code' => $s['code']], $s + ['credit_hours' => 100]));

        $this->subjects = $subjects;

        $matric = Program::firstOrCreate(['organization_id' => 1, 'code' => 'MAT-SCI'], [
            'name' => 'Matriculation (Science)', 'level' => 'matric', 'board' => 'BISE-LHR', 'duration_years' => 2,
            'campus_id' => $lhr->id,
        ]);

        $classes = collect(['Grade 9', 'Grade 10'])->map(function ($name) use ($matric, $lhr, $year) {
            $class = SchoolClass::firstOrCreate(
                ['campus_id' => $lhr->id, 'program_id' => $matric->id, 'name' => $name],
                ['organization_id' => 1, 'level_no' => $name === 'Grade 9' ? 9 : 10, 'academic_year_id' => $year->id],
            );
            $sync = $this->subjects->mapWithKeys(fn ($s) => [$s->id => ['is_compulsory' => $s->group === 'Compulsory']])->all();
            $class->subjects()->syncWithoutDetaching($sync);
            return $class;
        });

        $teacher = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk');
        $sections = [];
        foreach ($classes as $class) {
            foreach (['A', 'B'] as $sname) {
                $sections[] = Section::firstOrCreate(['class_id' => $class->id, 'name' => $sname], [
                    'organization_id' => 1, 'campus_id' => $lhr->id, 'capacity' => 40, 'class_teacher_id' => $teacher?->id,
                ]);
            }
        }

        // Demo students. Names are pooled by gender so a boy is never recorded as a girl —
        // the hostel relies on gender matching its blocks.
        $boys = ['Ali', 'Hamza', 'Bilal', 'Usman', 'Hassan', 'Ibrahim', 'Ahmed', 'Omar', 'Saad', 'Talha'];
        $girls = ['Zainab', 'Fatima', 'Ayesha', 'Mariam', 'Sara', 'Hira', 'Emaan', 'Noor', 'Laiba', 'Rayan'];
        $lastNames = ['Khan', 'Malik', 'Sheikh', 'Butt', 'Chaudhry', 'Raza', 'Hussain', 'Qureshi'];
        $i = 0;
        foreach ($sections as $section) {
            for ($s = 0; $s < 6; $s++) {
                $i++;
                $isGirl = $i % 3 === 0;
                $pool = $isGirl ? $girls : $boys;
                Student::firstOrCreate(
                    ['admission_no' => sprintf('S-LHR-2026-%04d', $i)],
                    [
                        'organization_id' => 1, 'campus_id' => $lhr->id,
                        'first_name' => $pool[$i % count($pool)],
                        'last_name' => $lastNames[$i % count($lastNames)],
                        'gender' => $isGirl ? 'female' : 'male',
                        'dob' => now()->subYears(14 + ($i % 3))->subDays(30 * ($i % 12))->toDateString(),
                        'father_name' => 'Muhammad ' . $lastNames[($i + 3) % count($lastNames)],
                        'guardian_phone' => '+92 3' . (300 + $i) . str_pad((string) (100000 + $i), 6, '0'),
                        'city' => 'Lahore',
                        'class_id' => $section->class_id, 'section_id' => $section->id,
                        'program_id' => $matric->id, 'academic_year_id' => $year->id,
                        'roll_no' => sprintf('26-%03d', $i),
                        'admission_date' => '2026-04-01', 'status' => 'active',
                    ],
                );
            }
        }

        // Timetable: Mon-Fri, 4 periods, Math/English/Urdu/Physics rotating, Saturday sports free slot skipped
        $periods = [['08:00', '08:40'], ['08:40', '09:20'], ['09:20', '10:00'], ['10:15', '10:55']];
        $rotSubjects = $subjects->values();
        foreach (array_slice($sections, 0, 2) as $si => $section) {
            for ($day = 1; $day <= 5; $day++) {
                foreach ($periods as $pi => [$st, $en]) {
                    $subj = $rotSubjects[($si + $day + $pi) % $rotSubjects->count()];
                    TimetableSlot::firstOrCreate(
                        ['section_id' => $section->id, 'day_of_week' => $day, 'start_time' => $st],
                        ['organization_id' => 1, 'campus_id' => $lhr->id, 'subject_id' => $subj->id, 'teacher_id' => $teacher?->id, 'end_time' => $en, 'room' => 'R-' . (10 + $si)],
                    );
                }
            }
        }

        // Demo admission pipeline
        $demoApps = [
            ['Fatima Noor', 'female', 'pending', 88],
            ['Hammad Iqbal', 'male', 'screening', 74],
            ['Rida Zahra', 'female', 'test', 91],
            ['Talha Sheikh', 'male', 'offered', 82],
            ['Anum Yousaf', 'female', 'accepted', 85],
            ['Daniyal Haider', 'male', 'rejected', 41],
            ['Zara Ahmed', 'female', 'waitlisted', 68],
            ['Musa Adnan', 'male', 'enrolled', 79],
        ];
        foreach ($demoApps as $idx => [$name, $gender, $status, $marks]) {
            Admission::firstOrCreate(
                ['application_no' => sprintf('ADM-2026-%04d', $idx + 1)],
                [
                    'organization_id' => 1, 'campus_id' => $lhr->id, 'applicant_name' => $name, 'gender' => $gender,
                    'dob' => now()->subYears(14)->subDays(11 * $idx)->toDateString(),
                    'father_name' => 'Muhammad Adnan', 'phone' => '+92 311' . str_pad((string) (1000000 + $idx * 7), 7, '0'),
                    'previous_school' => 'Beaconhouse Model Division', 'previous_marks' => $marks,
                    'program_id' => $matric->id, 'class_id' => $classes[0]->id, 'status' => $status,
                ],
            );
        }
    }

    private $subjects;
}
