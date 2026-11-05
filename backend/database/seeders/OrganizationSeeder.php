<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(['code' => 'SUNRISE'], [
            'name' => 'Sunrise Education Network',
            'email' => 'headoffice@sunrise.edu.pk',
            'phone' => '+92 21 111 786 473',
            'address' => 'Clifton Block 5, Karachi, Sindh',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ]);

        $campuses = [
            ['name' => 'Head Office — Karachi', 'code' => 'HO', 'city' => 'Karachi', 'school_type' => 'secondary', 'is_head_office' => true],
            ['name' => 'Sunrise Model School — DHA Lahore', 'code' => 'LHR', 'city' => 'Lahore', 'school_type' => 'secondary'],
            ['name' => 'Sunrise Cambridge System — Islamabad', 'code' => 'ISB', 'city' => 'Islamabad', 'school_type' => 'junior'],
            ['name' => 'Sunrise College — Peshawar', 'code' => 'PSH', 'city' => 'Peshawar', 'school_type' => 'college'],
        ];
        foreach ($campuses as $c) {
            $org->campuses()->firstOrCreate(['code' => $c['code']], array_merge(['is_head_office' => false], $c));
        }

        // Pakistan-specific organization defaults (campuses may override via settings rows).
        $defaults = [
            'academic.year_start' => '04-01',
            'academic.year_end' => '03-31',
            'academic.boards' => ['FBISE', 'BISE-KHI', 'BISE-LHR', 'BISE-ISB', 'BISE-PESH', 'Cambridge'],
            'academic.grading' => ['type' => 'percentage', 'pass_mark' => 40, 'scale' => ['A+' => 80, 'A' => 70, 'B' => 60, 'C' => 50, 'D' => 40]],
            'fees.currency' => 'PKR',
            'fees.due_day' => 10,
            'fees.late_fee_percent' => 2,
            'fees.channels' => ['cash', 'bank_transfer', 'jazzcash', 'easypaisa', 'cheque'],
            'attendance.warning_threshold' => 75,
            'hr.week_off' => 'sunday',
            'hr.leave_entitlement' => ['annual' => 14, 'casual' => 10, 'sick' => 12, 'earned' => 15],
            'payroll.eobi_percent' => 2,
            'payroll.eobi_wage_ceiling' => 60000,
            'payroll.tax_free_threshold' => 600000,
            'payroll.tax_percent' => 10,
            'communication.sms_sender' => 'SUNRISE',
            // Nothing leaves the server until an operator picks the gateway driver and
            // supplies SMS_GATEWAY_URL / SMS_GATEWAY_API_KEY in the environment.
            'communication.sms_driver' => 'log',
            'library.loan_days' => 14,
            'library.fine_per_day' => 5,
            'hostel.include_mess' => true,
        ];
        foreach ($defaults as $key => $value) {
            Setting::updateOrCreate(
                ['organization_id' => $org->id, 'campus_id' => null, 'key' => $key],
                ['value' => $value],
            );
        }

        // Default BISE-style grading bands (campuses may replace them wholesale).
        $bands = [
            [80, 'A+', 'Outstanding'], [70, 'A', 'Excellent'], [60, 'B', 'Very good'],
            [50, 'C', 'Good'], [40, 'D', 'Pass'], [33, 'E', 'Marginal pass'], [0, 'F', 'Fail'],
        ];
        foreach ($bands as [$min, $grade, $remark]) {
            \App\Models\GradingScale::firstOrCreate(
                ['organization_id' => $org->id, 'campus_id' => null, 'min_percent' => $min],
                ['grade' => $grade, 'remark' => $remark],
            );
        }
    }
}
