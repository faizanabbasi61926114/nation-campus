<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\Student;
use App\Models\User;
use App\Models\VisitorLog;
use Illuminate\Database\Seeder;

/**
 * Demo gate-pass log for the front desk. Sequences are per campus per day so the
 * receptionist page has both open and closed passes plus a purpose breakdown.
 */
class FrontDeskSeeder extends Seeder
{
    public function run(): void
    {
        $today = now()->toDateString();
        $seq = ['LHR' => 0, 'ISB' => 0];

        // [campus code, name, phone, purpose, meets-user-email|null, meets-student|null, in-time, out-time|null, gate, photo]
        $rows = [
            ['LHR', 'Imran Farooqi', '0300-5551001', 'Fee payment follow-up', null, null, '08:12', '08:47', 'Main Gate', 'yes'],
            ['LHR', 'Saeeda Begum', '0301-5551002', 'Parent — admission enquiry', null, null, '09:05', '09:40', 'Main Gate', 'yes'],
            ['LHR', 'Kamran Abbasi', '0302-5551003', 'Vendor — stationery supply', 'accounts.lhr@sunrise.edu.pk', null, '09:30', null, 'Service Gate', 'no'],
            ['LHR', 'Dr. Ayesha Noor', '0333-5551004', 'Medical camp coordinator', 'principal.lhr@sunrise.edu.pk', null, '10:15', null, 'Main Gate', 'yes'],
            ['LHR', 'Bilal Anwar', '0345-5551005', 'Parent — collecting report card', null, 'first', '11:02', '11:25', 'Main Gate', 'yes'],
            ['ISB', 'Hafiz Sultan', '0300-6661001', 'Guest lecture — Physics', 'principal.isb@sunrise.edu.pk', null, '10:00', null, 'East Gate', 'yes'],
            ['ISB', 'Rukhsana Kausar', '0301-6661002', 'Admission enquiry', null, null, '11:30', '11:58', 'East Gate', 'yes'],
        ];

        foreach ($rows as [$code, $name, $phone, $purpose, $userEmail, $meetsStudent, $inTime, $outTime, $gate, $photo]) {
            $campus = Campus::firstWhere('code', $code);
            if (!$campus) continue;

            $seq[$code]++;
            $passNo = sprintf('V-%s-%02d', date('ymd', strtotime($today)), $seq[$code]);

            $user = $userEmail ? User::firstWhere('email', $userEmail) : null;
            $student = $meetsStudent
                ? Student::where('campus_id', $campus->id)->where('status', 'active')->orderBy('id')->first()
                : null;

            VisitorLog::create([
                'organization_id' => $campus->organization_id,
                'campus_id' => $campus->id,
                'pass_no' => $passNo,
                'name' => $name,
                'phone' => $phone,
                'cnic' => '35202-' . random_int(1000000, 9999999) . '-' . random_int(0, 9),
                'purpose' => $purpose,
                'meets_user_id' => $user?->id,
                'meets_student_id' => $student?->id,
                'in_at' => "{$today} {$inTime}",
                'out_at' => $outTime ? "{$today} {$outTime}" : null,
                'gate' => $gate,
                'photo_id_taken' => $photo,
                'logged_by' => $user?->id ?? User::where('organization_id', $campus->organization_id)->value('id'),
            ]);
        }
    }
}
