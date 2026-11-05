<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstWhere('code', 'SUNRISE');
        $ho = Campus::firstWhere(['organization_id' => $org->id, 'code' => 'HO']);
        $lhr = Campus::firstWhere(['organization_id' => $org->id, 'code' => 'LHR']);
        $isb = Campus::firstWhere(['organization_id' => $org->id, 'code' => 'ISB']);
        $psh = Campus::firstWhere(['organization_id' => $org->id, 'code' => 'PSH']);

        $password = env('CMS_SEED_PASSWORD', 'Password123!');

        User::where('email', 'admin@sunrise.edu.pk')->update(['email' => 'admin@nationcampus.edu.pk']);

        $users = [
            ['name' => 'Super Admin', 'email' => 'admin@nationcampus.edu.pk', 'role' => 'super_admin', 'campus' => null, 'org_campus' => $ho->id],
            ['name' => 'Ayesha Malik', 'email' => 'ho.admin@sunrise.edu.pk', 'role' => 'head_office_admin', 'campus' => null, 'org_campus' => $ho->id],
            ['name' => 'Faisal Mahmood', 'email' => 'finance@sunrise.edu.pk', 'role' => 'finance_director', 'campus' => null, 'org_campus' => $ho->id],
            ['name' => 'Dr. Sana Qureshi', 'email' => 'principal.lhr@sunrise.edu.pk', 'role' => 'principal', 'campus' => $lhr->id, 'org_campus' => $lhr->id],
            ['name' => 'Bilal Ahmed', 'email' => 'principal.isb@sunrise.edu.pk', 'role' => 'principal', 'campus' => $isb->id, 'org_campus' => $isb->id],
            ['name' => 'Nasir Khan', 'email' => 'principal.psh@sunrise.edu.pk', 'role' => 'principal', 'campus' => $psh->id, 'org_campus' => $psh->id],
            ['name' => 'Hina Tariq', 'email' => 'admissions.lhr@sunrise.edu.pk', 'role' => 'admissions_officer', 'campus' => $lhr->id, 'org_campus' => $lhr->id],
            ['name' => 'Usman Raza', 'email' => 'teacher.lhr@sunrise.edu.pk', 'role' => 'class_teacher', 'campus' => $lhr->id, 'org_campus' => $lhr->id],
            ['name' => 'Nadia Aslam', 'email' => 'exams.lhr@sunrise.edu.pk', 'role' => 'exam_controller', 'campus' => $lhr->id, 'org_campus' => $lhr->id],
            ['name' => 'Kashif Zafar', 'email' => 'accounts.lhr@sunrise.edu.pk', 'role' => 'accounts_officer', 'campus' => $lhr->id, 'org_campus' => $lhr->id],
            ['name' => 'Rabia Shah', 'email' => 'hr.lhr@sunrise.edu.pk', 'role' => 'hr_manager', 'campus' => $lhr->id, 'org_campus' => $lhr->id],
            ['name' => 'Imran Baloch', 'email' => 'reception.khi@sunrise.edu.pk', 'role' => 'receptionist', 'campus' => $ho->id, 'org_campus' => $ho->id],
        ];

        foreach ($users as $u) {
            $user = User::firstOrCreate(['email' => $u['email']], [
                'organization_id' => $org->id,
                'campus_id' => $u['org_campus'],
                'name' => $u['name'],
                'password' => $password,
                'is_active' => true,
                'phone' => '+92 3' . random_int(0, 4) . '1' . random_int(10000000, 99999999),
            ]);
            $role = Role::firstWhere(['organization_id' => $org->id, 'slug' => $u['role']]);
            $user->roleAssignments()->syncWithoutDetaching([$role->id => ['campus_id' => $u['campus']]]);
        }
    }
}
