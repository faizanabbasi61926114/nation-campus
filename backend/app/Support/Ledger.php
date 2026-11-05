<?php

namespace App\Support;

use App\Models\Account;
use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\JournalLine;
use App\Models\JournalVoucher;
use Illuminate\Support\Facades\DB;

/**
 * Posts double-entry vouchers. Auto-posting is skipped when an organisation has no
 * chart of accounts installed yet, so fee/payroll flows keep working before Phase 6 setup.
 */
class Ledger
{
    public const CHANNEL_ACCOUNT = [
        'cash' => '1000',
        'bank_transfer' => '1010',
        'cheque' => '1010',
        'jazzcash' => '1020',
        'easypaisa' => '1030',
    ];

    public const CATEGORY_ACCOUNT = [
        'tuition' => '4000',
        'exam' => '4100',
        'examination' => '4100',
        'admission' => '4200',
        'transport' => '4300',
    ];

    public function accountByCode(int $organizationId, string $code): ?Account
    {
        return Account::withoutGlobalScopes()->where('organization_id', $organizationId)->where('code', $code)->first();
    }

    public function incomeCode(?FeeInvoice $invoice): string
    {
        $category = optional(optional($invoice)->structure)->category;
        return self::CATEGORY_ACCOUNT[$category ?? ''] ?? '4900';
    }

    /** Receipt voucher: Dr cash/bank, Cr fee income. */
    public function postFeePayment(FeePayment $payment): ?JournalVoucher
    {
        $invoice = $payment->invoice;
        $cash = $this->accountByCode($payment->organization_id, self::CHANNEL_ACCOUNT[$payment->channel] ?? '1000');
        $income = $this->accountByCode($payment->organization_id, $this->incomeCode($invoice));
        if (!$cash || !$income) return null;

        $student = optional($invoice)->student;
        $who = $student ? trim("{$student->first_name} {$student->last_name}") : '';
        return $this->post([
            'organization_id' => $payment->organization_id,
            'campus_id' => $payment->campus_id,
            'voucher_date' => $payment->paid_on instanceof \DateTimeInterface ? $payment->paid_on->format('Y-m-d') : now()->toDateString(),
            'type' => 'receipt',
            'narrative' => 'Fee receipt ' . $payment->receipt_no . ($who ? " — $who" : ''),
            'source' => 'fee_payment',
            'source_ref' => $payment->receipt_no,
            'posted_by' => $payment->received_by,
            'lines' => [
                ['account_id' => $cash->id, 'debit' => $payment->amount, 'credit' => 0, 'memo' => $payment->channel],
                ['account_id' => $income->id, 'debit' => 0, 'credit' => $payment->amount, 'memo' => optional($invoice)->title],
            ],
        ]);
    }

    /** Payment salary voucher: Dr salaries, Cr bank. */
    public function postPayrollRun(int $organizationId, int $campusId, string $month, float $net, int $userId, string $cashCode = '1010'): ?JournalVoucher
    {
        $bank = $this->accountByCode($organizationId, $cashCode);
        $salaries = $this->accountByCode($organizationId, '5000');
        if (!$bank || !$salaries) return null;

        return $this->post([
            'organization_id' => $organizationId,
            'campus_id' => $campusId,
            'voucher_date' => now()->toDateString(),
            'type' => 'payment',
            'narrative' => 'Salaries payable for ' . $month,
            'source' => 'payroll',
            'source_ref' => $month,
            'posted_by' => $userId,
            'lines' => [
                ['account_id' => $salaries->id, 'debit' => $net, 'credit' => 0, 'memo' => 'Net salary'],
                ['account_id' => $bank->id, 'debit' => 0, 'credit' => $net, 'memo' => 'Bank payout'],
            ],
        ]);
    }

    public function post(array $voucher): ?JournalVoucher
    {
        $debits = round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $voucher['lines'])), 2);
        $credits = round(array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $voucher['lines'])), 2);
        if (count($voucher['lines']) < 2 || $debits <= 0 || abs($debits - $credits) > 0.009) return null;

        return DB::transaction(function () use ($voucher, $debits) {
            $model = JournalVoucher::create([
                'organization_id' => $voucher['organization_id'],
                'campus_id' => $voucher['campus_id'],
                'voucher_no' => $this->nextVoucherNo($voucher['campus_id'], $voucher['voucher_date']),
                'voucher_date' => $voucher['voucher_date'],
                'type' => $voucher['type'] ?? 'journal',
                'narrative' => $voucher['narrative'],
                'source' => $voucher['source'] ?? 'manual',
                'source_ref' => $voucher['source_ref'] ?? null,
                'amount' => $debits,
                'posted_by' => $voucher['posted_by'] ?? null,
                'status' => 'posted',
            ]);
            foreach ($voucher['lines'] as $line) {
                JournalLine::create([
                    'voucher_id' => $model->id,
                    'account_id' => $line['account_id'],
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'memo' => $line['memo'] ?? null,
                ]);
            }
            return $model;
        });
    }

    public function void(?JournalVoucher $voucher): void
    {
        if ($voucher) $voucher->update(['status' => 'reversed']);
    }

    private function nextVoucherNo(int $campusId, ?string $voucherDate = null): string
    {
        $period = ($voucherDate ? \Carbon\Carbon::parse($voucherDate) : now())->format('Ym');
        $code = optional(\App\Models\Campus::find($campusId))->code ?? 'HO';
        $prefix = 'VCH-' . $period . '-' . $code . '-';
        $max = JournalVoucher::withoutGlobalScopes()->where('voucher_no', 'like', $prefix . '%')->max('voucher_no');
        $seq = $max ? (int) substr($max, -5) : 0;
        do {
            $seq++;
            $no = $prefix . sprintf('%05d', $seq);
        } while (JournalVoucher::withoutGlobalScopes()->where('voucher_no', $no)->exists());
        return $no;
    }
}
