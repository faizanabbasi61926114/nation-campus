<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\Department;
use App\Models\Designation;
use App\Models\LeaveApplication;
use App\Models\Staff;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class HrSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $isb = Campus::firstWhere('code', 'ISB');

        $departments = [];
        foreach (['ACAD' => 'Academic', 'ADMIN' => 'Administration', 'FIN' => 'Finance & Accounts', 'SUP' => 'Support Services'] as $code => $name) {
            $departments[$code] = Department::firstOrCreate(
                ['organization_id' => 1, 'code' => $code],
                ['name' => $name, 'is_active' => true],
            )->id;
        }

        $designations = [];
        foreach ([
            ['Head of Campus', 'senior', 'ACAD', 'BPS-20', 220000, 350000],
            ['Senior Teacher', 'teaching', 'ACAD', 'BPS-17', 110000, 180000],
            ['Teacher', 'teaching', 'ACAD', 'BPS-16', 70000, 110000],
            ['Lab Technician', 'support', 'ACAD', 'BPS-9', 45000, 65000],
            ['Librarian', 'support', 'ACAD', 'BPS-9', 45000, 60000],
            ['Admin Officer', 'administrative', 'ADMIN', 'BPS-16', 75000, 120000],
            ['HR Manager', 'administrative', 'ADMIN', 'BPS-17', 95000, 140000],
            ['Accounts Officer', 'administrative', 'FIN', 'BPS-16', 80000, 130000],
            ['Cashier', 'administrative', 'FIN', 'BPS-9', 50000, 70000],
            ['Security Guard', 'support', 'SUP', 'BPS-4', 32000, 40000],
            ['Cleaner', 'support', 'SUP', 'BPS-1', 28000, 34000],
        ] as [$name, $category, $dept, $grade, $min, $max]) {
            $designations[$name] = Designation::firstOrCreate(
                ['organization_id' => 1, 'name' => $name],
                ['category' => $category, 'grade' => $grade, 'department_id' => $departments[$dept],
                    'min_salary' => $min, 'max_salary' => $max],
            )->id;
        }

        $users = User::whereIn('email', [
            'principal.lhr@sunrise.edu.pk', 'teacher.lhr@sunrise.edu.pk',
            'accounts.lhr@sunrise.edu.pk', 'hr.lhr@sunrise.edu.pk',
            'principal.isb@sunrise.edu.pk',
        ])->get()->keyBy('email');

        $rows = [
            // [campus, employee_no, first, last, gender, designation, basic, house%, medical, other ded, joined, status, user email]
            [$lhr, 'S-LHR-0001', 'Sana', 'Qureshi', 'female', 'Head of Campus', 260000, 40, 25000, 0, '2019-04-01', 'active', 'principal.lhr@sunrise.edu.pk'],
            [$lhr, 'S-LHR-0002', 'Usman', 'Raza', 'male', 'Senior Teacher', 125000, 35, 12000, 2000, '2020-08-15', 'active', 'teacher.lhr@sunrise.edu.pk'],
            [$lhr, 'S-LHR-0003', 'Mehwish', 'Khan', 'female', 'Senior Teacher', 118000, 35, 12000, 0, '2021-04-05', 'active', null],
            [$lhr, 'S-LHR-0004', 'Talal', 'Hussain', 'male', 'Teacher', 82000, 30, 8000, 0, '2022-09-01', 'active', null],
            [$lhr, 'S-LHR-0005', 'Saima', 'Butt', 'female', 'Teacher', 78000, 30, 8000, 0, '2023-04-10', 'probation', null],
            [$lhr, 'S-LHR-0006', 'Naveed', 'Akhtar', 'male', 'Lab Technician', 54000, 25, 5000, 0, '2021-11-01', 'active', null],
            [$lhr, 'S-LHR-0007', 'Zubaida', 'Ali', 'female', 'Librarian', 51000, 25, 5000, 0, '2018-07-16', 'active', null],
            [$lhr, 'S-LHR-0008', 'Kashif', 'Zafar', 'male', 'Accounts Officer', 98000, 35, 10000, 0, '2020-01-20', 'active', 'accounts.lhr@sunrise.edu.pk'],
            [$lhr, 'S-LHR-0009', 'Rabia', 'Shah', 'female', 'HR Manager', 105000, 35, 10000, 0, '2019-10-01', 'active', 'hr.lhr@sunrise.edu.pk'],
            [$lhr, 'S-LHR-0010', 'Adnan', 'Iqbal', 'male', 'Admin Officer', 86000, 30, 8000, 0, '2022-02-14', 'active', null],
            [$lhr, 'S-LHR-0011', 'Shakeel', 'Ahmed', 'male', 'Security Guard', 34000, 20, 3000, 500, '2023-08-01', 'active', null],
            [$lhr, 'S-LHR-0012', 'Ghulam', 'Mustafa', 'male', 'Cleaner', 30000, 20, 2500, 0, '2024-01-08', 'active', null],
            [$isb, 'S-ISB-0001', 'Bilal', 'Ahmed', 'male', 'Head of Campus', 245000, 40, 25000, 0, '2020-04-01', 'active', 'principal.isb@sunrise.edu.pk'],
            [$isb, 'S-ISB-0002', 'Hira', 'Siddiqui', 'female', 'Senior Teacher', 121000, 35, 12000, 0, '2021-09-06', 'active', null],
            [$isb, 'S-ISB-0003', 'Owais', 'Sheikh', 'male', 'Teacher', 80000, 30, 8000, 0, '2022-04-11', 'active', null],
            [$isb, 'S-ISB-0004', 'Anum', 'Noor', 'female', 'Librarian', 49000, 25, 5000, 0, '2023-02-20', 'probation', null],
            [$isb, 'S-ISB-0005', 'Rashid', 'Minhas', 'male', 'Security Guard', 33000, 20, 3000, 0, '2024-05-13', 'active', null],
        ];

        $staff = [];
        foreach ($rows as [$campus, $no, $first, $last, $gender, $designation, $basic, $hr, $medical, $ded, $joined, $status, $email]) {
            $staff[$no] = Staff::firstOrCreate(
                ['employee_no' => $no],
                [
                    'organization_id' => 1, 'campus_id' => $campus->id,
                    'user_id' => optional($users->firstWhere('email', $email))->id,
                    'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
                    'email' => strtolower($first . '.' . $last) . '@sunrise.edu.pk',
                    'phone' => '+92 3' . random_int(0, 5) . '1-' . random_int(1000000, 9999999),
                    'designation_id' => $designations[$designation],
                    'department_id' => $departments[$this->departmentFor($designation)],
                    'joined_on' => $joined,
                    'confirmed_on' => $status === 'probation' ? null : date('Y-m-d', strtotime($joined . ' +6 months')),
                    'base_salary' => $basic, 'house_rent_percent' => $hr,
                    'medical_allowance' => $medical, 'other_deductions' => $ded,
                    'bank_account' => 'BAHT-' . random_int(100000000, 999999999),
                    'status' => $status,
                ],
            );
        }

        // Leave history that feeds both the leave board and October's unpaid-leave deduction.
        $leaves = [
            ['S-LHR-0002', 'annual', '2026-07-06', '2026-07-17', 'approved', 'Family visit to Murree.'],
            ['S-LHR-0004', 'sick', '2026-09-08', '2026-09-10', 'approved', 'Dengue fever, doctor advised rest.'],
            ['S-LHR-0007', 'casual', '2026-10-12', '2026-10-12', 'approved', 'Eid-un-Milad-un-Nabi holiday.'],
            ['S-LHR-0011', 'unpaid', '2026-10-05', '2026-10-09', 'approved', 'Unauthorised absence after the first week, regularised as unpaid.'],
            ['S-LHR-0012', 'unpaid', '2026-10-19', '2026-10-21', 'pending', 'Requested three days unpaid leave for a family emergency in Vehari.'],
            ['S-ISB-0002', 'earned', '2026-11-02', '2026-11-13', 'pending', 'Umrah with parents.'],
            ['S-ISB-0003', 'media', '2026-10-26', '2026-10-30', 'pending', 'BISE intermediate practical exams.'],
            ['S-LHR-0005', 'casual', '2026-08-19', '2026-08-20', 'rejected', 'Same-day substitution was not available.'],
        ];

        $decider = $users->firstWhere('email', 'hr.lhr@sunrise.edu.pk');
        foreach ($leaves as [$no, $type, $from, $to, $status, $reason]) {
            $member = $staff[$no];
            $days = $this->workingDays($from, $to, $member->campus_id);
            LeaveApplication::firstOrCreate(
                ['staff_id' => $member->id, 'from_date' => $from, 'type' => $type],
                [
                    'organization_id' => 1, 'campus_id' => $member->campus_id,
                    'to_date' => $to, 'days' => $days, 'reason' => $reason, 'status' => $status,
                    'decided_by' => $status === 'pending' ? null : $decider->id,
                    'decision_notes' => $status === 'rejected' ? 'Cover was unavailable.' : null,
                ],
            );
        }
    }

    private function departmentFor(string $designation): string
    {
        return match ($designation) {
            'Accounts Officer' => 'FIN',
            'HR Manager', 'Admin Officer' => 'ADMIN',
            'Security Guard', 'Cleaner' => 'SUP',
            default => 'ACAD',
        };
    }

    /** Countable leave days, skipping the configured week off (Pakistan campuses: Sunday). */
    private function workingDays(string $from, string $to, int $campusId): int
    {
        $weekOff = strtolower((string) (Setting::getFor(1, $campusId, 'hr.week_off') ?? 'sunday'));
        $map = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $skip = $map[$weekOff] ?? 0;

        $days = 0;
        $cursor = strtotime($from);
        $end = strtotime($to);
        while ($cursor <= $end) {
            if ((int) date('w', $cursor) !== $skip) $days++;
            $cursor = strtotime('+1 day', $cursor);
        }
        return $days;
    }
}
