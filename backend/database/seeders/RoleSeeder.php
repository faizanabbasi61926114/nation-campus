<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstWhere('code', 'SUNRISE');

        $all = Permission::pluck('id')->all();

        $definitions = [
            ['slug' => 'super_admin', 'name' => 'Super Administrator', 'permissions' => ['*'], 'is_system' => true],
            ['slug' => 'head_office_admin', 'name' => 'Head Office Administrator', 'is_system' => true, 'permissions' => array_values(array_diff($all, ['*']))],
            ['slug' => 'principal', 'name' => 'Campus Principal', 'is_system' => true, 'permissions' => array_values(array_filter(
                array_merge($all, []),
                fn ($p) => $p !== '*' && !in_array($p, ['campuses.manage', 'users.manage', 'roles.manage', 'settings.manage', 'payroll.process'], true),
            ))],
            ['slug' => 'admissions_officer', 'name' => 'Admissions Officer', 'permissions' => ['dashboard.view', 'admissions.view', 'admissions.manage', 'students.view', 'students.manage', 'reports.view', 'communication.manage']],
            ['slug' => 'class_teacher', 'name' => 'Class Teacher', 'permissions' => ['dashboard.view', 'students.view', 'academics.view', 'attendance.view', 'attendance.mark', 'exams.view', 'exams.marks_entry', 'communication.view', 'communication.manage', 'calendar.view', 'library.view', 'discipline.view', 'discipline.manage']],
            ['slug' => 'exam_controller', 'name' => 'Exam Controller', 'permissions' => ['dashboard.view', 'academics.view', 'exams.view', 'exams.schedule', 'exams.marks_entry', 'exams.results_publish', 'students.view', 'reports.view', 'reports.export', 'calendar.view', 'calendar.manage']],
            ['slug' => 'accounts_officer', 'name' => 'Accounts Officer', 'permissions' => ['dashboard.view', 'students.view', 'fees.view', 'fees.manage', 'fees.payments_record', 'accounts.view', 'accounts.manage', 'reports.view', 'reports.export', 'transport.view', 'hostel.view', 'library.view']],
            ['slug' => 'finance_director', 'name' => 'Finance Director (Head Office)', 'permissions' => ['dashboard.view', 'students.view', 'fees.view', 'accounts.view', 'accounts.manage', 'payroll.view', 'payroll.process', 'fees.waivers_approve', 'reports.view', 'reports.export']],
            ['slug' => 'hr_manager', 'name' => 'HR Manager', 'permissions' => ['dashboard.view', 'hr.view', 'hr.manage', 'hr.leave_approve', 'payroll.view', 'payroll.process', 'staff.view', 'staff.manage', 'reports.view']],
            ['slug' => 'receptionist', 'name' => 'Receptionist', 'permissions' => ['dashboard.view', 'admissions.view', 'students.view', 'communication.view', 'communication.manage', 'frontdesk.view', 'frontdesk.manage', 'calendar.view', 'documents.view', 'transport.view']],
            ['slug' => 'librarian', 'name' => 'Librarian', 'permissions' => ['dashboard.view', 'students.view', 'library.view', 'library.manage', 'calendar.view', 'communication.view']],
            ['slug' => 'hostel_warden', 'name' => 'Hostel Warden', 'permissions' => ['dashboard.view', 'students.view', 'hostel.view', 'hostel.manage', 'discipline.view', 'discipline.manage', 'calendar.view', 'communication.view']],
            // Household account: a parent reads their own children's records and the notices
            // addressed to them, and gets no module access beyond that.
            ['slug' => 'guardian', 'name' => 'Parent / Guardian Portal', 'permissions' => ['dashboard.view', 'communication.view', 'communication.reply', 'guardian.view']],
        ];

        foreach ($definitions as $def) {
            $role = Role::firstOrCreate(
                ['organization_id' => $org->id, 'slug' => $def['slug']],
                ['name' => $def['name'], 'is_system' => $def['is_system'] ?? false],
            );
            $role->permissions()->sync($def['permissions']);
        }
    }
}
