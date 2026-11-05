<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'dashboard' => ['dashboard.view'],
            'campuses' => ['campuses.view', 'campuses.manage'],
            'users' => ['users.view', 'users.manage', 'roles.manage'],
            'settings' => ['settings.view', 'settings.manage', 'settings.manage_campus'],
            'audit' => ['audit.view'],
            'admissions' => ['admissions.view', 'admissions.manage'],
            'students' => ['students.view', 'students.manage'],
            'academics' => ['academics.view', 'academics.manage'],
            'attendance' => ['attendance.view', 'attendance.mark', 'attendance.manage'],
            'exams' => ['exams.view', 'exams.schedule', 'exams.marks_entry', 'exams.results_publish'],
            'fees' => ['fees.view', 'fees.manage', 'fees.payments_record', 'fees.waivers_approve'],
            'accounts' => ['accounts.view', 'accounts.manage'],
            'payroll' => ['payroll.view', 'payroll.process'],
            'hr' => ['hr.view', 'hr.manage', 'hr.leave_approve'],
            'staff' => ['staff.view', 'staff.manage'],
            'communication' => ['communication.view', 'communication.manage', 'communication.reply'],
            'reports' => ['reports.view', 'reports.export'],

            // Campus life — the modules a Pakistani school runs alongside academics
            'library' => ['library.view', 'library.manage'],
            'transport' => ['transport.view', 'transport.manage'],
            'hostel' => ['hostel.view', 'hostel.manage'],
            'front_desk' => ['frontdesk.view', 'frontdesk.manage'],
            'documents' => ['documents.view', 'documents.manage'],
            'calendar' => ['calendar.view', 'calendar.manage'],
            'discipline' => ['discipline.view', 'discipline.manage'],

            // Family portal — reads are bounded by the guardian link on the student record,
            // not by the campus the reader happens to be signed into.
            'guardian' => ['guardian.view'],
        ];

        foreach ($catalog as $group => $perms) {
            foreach ($perms as $perm) {
                Permission::firstOrCreate(
                    ['id' => $perm],
                    ['group' => $group, 'description' => str_replace('.', ' ', ucwords($perm, '.'))],
                );
            }
        }
        Permission::firstOrCreate(['id' => '*'], ['group' => 'system', 'description' => 'Super administrator — all permissions']);
    }
}
