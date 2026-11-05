<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\CertificateRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample certificate requests: one per stage of the workflow, so the desk, the approval
 * queue and the issued register all have something to show. Serial numbers follow the same
 * CERT/<campus>/<sequence> rule the controller uses, and never reuse an allocated one.
 */
class CertificateSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $students = Student::withoutGlobalScopes()->where('campus_id', $lhr?->id)->orderBy('id')->get();
        if ($students->isEmpty()) return; // students not seeded yet

        $teacher = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk');
        $principal = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk');
        $admissions = User::firstWhere('email', 'admissions.lhr@sunrise.edu.pk');

        $sequence = (int) CertificateRequest::withoutGlobalScopes()
            ->where('organization_id', 1)->whereNotNull('reference_no')->pluck('reference_no')
            ->reduce(fn ($max, $ref) => max((int) $max, CertificateRequest::serialOf((string) $ref)), 0);

        $requests = [
            ['i' => 0, 'type' => 'bonafide', 'purpose' => 'opening a savings account with Meezan Bank', 'fee' => 300, 'status' => 'pending', 'days_ago' => 1, 'by' => $teacher],
            ['i' => 1, 'type' => 'transfer', 'purpose' => 'admission to Grade 11 at another school', 'title' => 'To the Principal, Government College Women University', 'fee' => 500, 'status' => 'pending', 'days_ago' => 2, 'by' => $admissions],
            ['i' => 2, 'type' => 'character', 'purpose' => 'scholarship application for the Cambridge programme', 'fee' => 400, 'status' => 'approved', 'days_ago' => 5, 'by' => $teacher, 'decided_days_ago' => 3],
            ['i' => 3, 'type' => 'marksheet', 'purpose' => 'attestation from BISE Lahore', 'fee' => 250, 'status' => 'approved', 'days_ago' => 6, 'by' => $admissions, 'decided_days_ago' => 4],
            ['i' => 5, 'type' => 'bonafide', 'purpose' => 'a duplicate student card', 'fee' => 300, 'status' => 'issued', 'days_ago' => 18, 'by' => $admissions, 'decided_days_ago' => 17, 'issued_days_ago' => 15],
            ['i' => 4, 'type' => 'internship', 'purpose' => 'a four-week industrial internship at Nestle Pakistan', 'fee' => 0, 'status' => 'issued', 'days_ago' => 12, 'by' => $teacher, 'decided_days_ago' => 10, 'issued_days_ago' => 8],
            ['i' => 6, 'type' => 'other', 'purpose' => 'a visa appointment for a family visit to the United Arab Emirates', 'title' => 'To the Visa Officer, Embassy of the United Arab Emirates', 'fee' => 600, 'status' => 'rejected', 'days_ago' => 9, 'by' => $teacher, 'decided_days_ago' => 7,
                'remarks' => 'The fee challan was not attached. The guardian may re-apply with the receipt.'],
        ];

        foreach ($requests as $r) {
            $student = $students->get($r['i']) ?? $students->first();
            $exists = CertificateRequest::withoutGlobalScopes()
                ->where(['organization_id' => 1, 'student_id' => $student->id, 'type' => $r['type']])->exists();
            if ($exists) continue;

            $decided = in_array($r['status'], ['approved', 'rejected', 'issued'], true);
            $issued = $r['status'] === 'issued';

            CertificateRequest::create([
                'organization_id' => 1,
                'campus_id' => $lhr->id,
                'student_id' => $student->id,
                'type' => $r['type'],
                'title' => $r['title'] ?? null,
                'purpose' => $r['purpose'],
                'fee' => $r['fee'],
                'status' => $r['status'],
                'reference_no' => $issued ? sprintf('CERT/%s/%04d', $lhr->code, ++$sequence) : null,
                'requested_by' => $r['by']?->id,
                'decided_by' => $decided ? $principal?->id : null,
                'decided_at' => $decided ? now()->subDays($r['decided_days_ago']) : null,
                'issued_at' => $issued ? now()->subDays($r['issued_days_ago']) : null,
                'remarks' => $r['remarks'] ?? ($decided ? 'Verified against the fee ledger before approval.' : null),
                'created_at' => now()->subDays($r['days_ago']),
                'updated_at' => now()->subDays($r['days_ago']),
            ]);
        }
    }
}
