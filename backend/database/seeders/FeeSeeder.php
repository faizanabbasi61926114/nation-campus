<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\FeeWaiver;
use App\Models\Student;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Database\Seeder;

class FeeSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $cashier = User::firstWhere('email', 'accounts.lhr@sunrise.edu.pk');

        $tuition = FeeStructure::firstOrCreate(
            ['campus_id' => $lhr->id, 'name' => 'Monthly Tuition Fee — 2026'],
            [
                'organization_id' => 1, 'category' => 'tuition', 'amount' => 5000,
                'gst_percent' => 0, 'frequency' => 'monthly', 'due_day' => 10, 'is_active' => true,
            ],
        );
        $examFee = FeeStructure::firstOrCreate(
            ['campus_id' => $lhr->id, 'name' => 'Annual Examination Fee — 2026'],
            [
                'organization_id' => 1, 'category' => 'examination', 'amount' => 2000,
                'gst_percent' => 0, 'frequency' => 'one_time', 'due_day' => 25, 'is_active' => true,
            ],
        );

        $students = Student::where('campus_id', $lhr->id)->where('status', 'active')->orderBy('id')->get();
        $months = ['2026-09' => 10, '2026-10' => 15, '2026-11' => 15];
        $seq = 0;

        foreach ($months as $month => $dueDay) {
            foreach ($students as $i => $st) {
                $invoice = FeeInvoice::firstOrCreate(
                    ['student_id' => $st->id, 'fee_structure_id' => $tuition->id, 'session_month' => $month],
                    [
                        'organization_id' => 1, 'campus_id' => $lhr->id,
                        'title' => 'Monthly Tuition Fee', 'amount' => 5000, 'gst_amount' => 0,
                        'discount' => 0, 'late_fee' => 0, 'status' => 'unpaid',
                        'issue_date' => "$month-01", 'due_date' => sprintf('%s-%02d', $month, $dueDay),
                    ],
                );

                // Deterministic distribution: ~70% paid, ~15% partial, ~15% unpaid/overdue.
                $bucket = $i % 20;
                if ($bucket < 14) {
                    $this->pay($invoice, 5000, $month, $cashier, ++$seq);
                } elseif ($bucket < 17) {
                    $this->pay($invoice, 2500, $month, $cashier, ++$seq);
                }
                if ($month === '2026-09' && $bucket >= 17) {
                    $invoice->update(['late_fee' => 100, 'status' => 'partial']); // Rs 2% late fee, still overdue
                    $this->pay($invoice, 1000, $month, $cashier, ++$seq);
                }
            }
        }

        // One-time exam fee for the first 10 students.
        foreach ($students->take(10) as $i => $st) {
            $invoice = FeeInvoice::firstOrCreate(
                ['student_id' => $st->id, 'fee_structure_id' => $examFee->id, 'session_month' => '2026-10'],
                [
                    'organization_id' => 1, 'campus_id' => $lhr->id,
                    'title' => 'Annual Examination Fee', 'amount' => 2000, 'gst_amount' => 0,
                    'discount' => 0, 'late_fee' => 0, 'status' => 'unpaid',
                    'issue_date' => '2026-10-01', 'due_date' => '2026-10-25',
                ],
            );
            if ($i % 2 === 0) $this->pay($invoice, 2000, '2026-10', $cashier, ++$seq);
        }

        // A pending concession waiver for an overdue September student.
        $waiverStudent = $students->get(18);
        $october = FeeInvoice::firstWhere(['student_id' => $waiverStudent->id, 'fee_structure_id' => $tuition->id, 'session_month' => '2026-10']);
        FeeWaiver::firstOrCreate(
            ['student_id' => $waiverStudent->id, 'invoice_id' => $october?->id, 'reason' => 'Father lost employment — hardship request'],
            [
                'organization_id' => 1, 'campus_id' => $lhr->id, 'percent' => 50,
                'amount' => null, 'status' => 'pending', 'requested_by' => $cashier?->id,
            ],
        );
    }

    private function pay(FeeInvoice $invoice, float $amount, string $month, ?User $cashier, int $seq): void
    {
        $receipt = sprintf('RCP-LHR-%s-%05d', str_replace('-', '', $month), $seq);
        if (FeePayment::where('receipt_no', $receipt)->exists()) return;
        $payment = FeePayment::create([
            'organization_id' => 1, 'campus_id' => $invoice->campus_id, 'invoice_id' => $invoice->id,
            'receipt_no' => $receipt, 'amount' => $amount,
            'channel' => $seq % 3 === 0 ? 'jazzcash' : ($seq % 3 === 1 ? 'cash' : 'bank_transfer'),
            'reference' => 'SEED-' . $seq,
            // Next month's invoices are billed in advance; never post a receipt dated in the future.
            'paid_on' => min((string) $invoice->due_date, now()->toDateString()),
            'received_by' => $cashier?->id, 'status' => 'cleared',
        ]);
        app(Ledger::class)->postFeePayment($payment);
        $invoice->load('payments');
        $due = $invoice->totalDue();
        $paid = $invoice->paidAmount();
        $invoice->update(['status' => $paid >= $due - 0.001 ? 'paid' : 'partial']);
    }
}
