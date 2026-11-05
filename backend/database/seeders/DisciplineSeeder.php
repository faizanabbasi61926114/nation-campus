<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\DisciplineCase;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample discipline register: a spread of categories and severities, one repeat offender so
 * the "most frequent" panel has something to show, and a closed case with the action recorded.
 */
class DisciplineSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $students = Student::withoutGlobalScopes()->where('campus_id', $lhr?->id)->orderBy('id')->get();
        if ($students->isEmpty()) return; // students not seeded yet

        $teacher = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk');
        $principal = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk');
        $headOffice = User::firstWhere('email', 'ho.admin@sunrise.edu.pk');

        $cases = [
            // A repeat offender: three open cases, two of them major.
            ['i' => 2, 'days_ago' => 4, 'category' => 'bullying', 'severity' => 'major', 'action' => 'none', 'status' => 'open', 'by' => $teacher,
                'description' => 'Repeatedly blocked a younger student in the corridor and took his lunch box. Two guardians have now complained.'],
            ['i' => 2, 'days_ago' => 17, 'category' => 'damage', 'severity' => 'major', 'action' => 'counselled', 'status' => 'open', 'by' => $principal,
                'description' => 'Broke the classroom window pane while throwing a stool. Recovery of the repair cost has been raised with the guardian.'],
            ['i' => 2, 'days_ago' => 31, 'category' => 'uniform', 'severity' => 'minor', 'action' => 'verbal_warning', 'status' => 'open', 'by' => $teacher,
                'description' => 'Attended without the school blazer for the third day running.'],

            ['i' => 6, 'days_ago' => 6, 'category' => 'punctuality', 'severity' => 'minor', 'action' => 'parent_called', 'status' => 'open', 'by' => $teacher,
                'description' => 'Late assembly nine times this month. Guardian called and asked to sign the register.'],
            ['i' => 9, 'days_ago' => 10, 'category' => 'attendance', 'severity' => 'moderate', 'action' => 'detention', 'status' => 'open', 'by' => $principal,
                'description' => 'Attendance below the 75% warning threshold. Two Saturday detentions scheduled with the class teacher.'],
            ['i' => 11, 'days_ago' => 21, 'category' => 'conduct', 'severity' => 'major', 'action' => 'suspension', 'days' => 3, 'parent' => true, 'status' => 'open', 'by' => $principal,
                'description' => 'Physically assaulted another student in the playground. Suspended for three days with the guardian asked to attend.'],

            ['i' => 4, 'days_ago' => 26, 'category' => 'other', 'severity' => 'minor', 'action' => 'verbal_warning', 'status' => 'closed', 'by' => $headOffice,
                'description' => 'Used a mobile phone during the examination.',
                'remarks' => 'Phone confiscated for the day and returned to the guardian. Student cautioned in writing and the matter will not be carried on the record.'],
            ['i' => 8, 'days_ago' => 40, 'category' => 'bullying', 'severity' => 'moderate', 'action' => 'counselled', 'status' => 'closed', 'by' => $principal,
                'description' => 'Name-calling in the class group chat.',
                'remarks' => 'Counselled by the class teacher with the guardian present. Both students have apologised and no further incident has been reported.'],
        ];

        foreach ($cases as $c) {
            $student = $students->get($c['i']) ?? $students->first();
            $duplicate = DisciplineCase::withoutGlobalScopes()
                ->where(['student_id' => $student->id, 'category' => $c['category'], 'incident_on' => now()->subDays($c['days_ago'])->toDateString()])
                ->exists();
            if ($duplicate) continue;

            DisciplineCase::create([
                'organization_id' => 1,
                'campus_id' => $lhr->id,
                'student_id' => $student->id,
                'incident_on' => now()->subDays($c['days_ago'])->toDateString(),
                'category' => $c['category'],
                'severity' => $c['severity'],
                'description' => $c['description'],
                'action' => $c['action'],
                'suspension_days' => $c['days'] ?? 0,
                'parent_notified' => $c['parent'] ?? in_array($c['action'], ['parent_called', 'suspension'], true),
                'status' => $c['status'],
                'handled_by' => $c['by']?->id,
                'remarks' => $c['remarks'] ?? null,
                'created_at' => now()->subDays($c['days_ago']),
                'updated_at' => now()->subDays(max(0, $c['days_ago'] - 2)),
            ]);
        }
    }
}
