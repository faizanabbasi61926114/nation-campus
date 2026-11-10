<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            OrganizationSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            AcademicsSeeder::class,
            ExamsSeeder::class,
            HrSeeder::class,
            // After HR: the staff register is the HR list, so the records have to exist first.
            AttendanceSeeder::class,
            AccountsSeeder::class,
            FeeSeeder::class,
            PayrollSeeder::class,
            CommunicationSeeder::class,
            // Campus life runs last: it needs the students, staff and classes the core modules create.
            CalendarSeeder::class,
            LibrarySeeder::class,
            TransportSeeder::class,
            HostelSeeder::class,
            FrontDeskSeeder::class,
            CertificateSeeder::class,
            DisciplineSeeder::class,
        ]);
    }
}
