<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Campus;
use App\Models\JournalVoucher;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Database\Seeder;

/**
 * Install a conventional Pakistani school chart of accounts and post the opening
 * fund plus a few recurring campus expenses, so the ledgers are usable on day one.
 */
class AccountsSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $isb = Campus::firstWhere('code', 'ISB');
        $accountsOfficer = User::firstWhere('email', 'accounts.lhr@sunrise.edu.pk');

        $chart = [
            // code, name, type
            ['1000', 'Cash on Hand — Campus Safe', 'asset'],
            ['1010', 'Bank — Meezan Current Account', 'asset'],
            ['1020', 'Mobile Account — JazzCash', 'asset'],
            ['1030', 'Mobile Account — Easypaisa', 'asset'],
            ['1100', 'Fees Receivable', 'asset'],
            ['1200', 'Furniture & Fixtures', 'asset'],
            ['1300', 'Computers & Lab Equipment', 'asset'],
            ['1400', 'Prepaid Building Rent', 'asset'],
            ['2000', 'Salaries Payable', 'liability'],
            ['2100', 'EOBI & Social Security Payable', 'liability'],
            ['2200', 'Income Tax Deducted Payable', 'liability'],
            ['2300', 'Advance Fees Received', 'liability'],
            ['2400', 'Creditors — Stationery & Supplies', 'liability'],
            ['3000', 'Opening Fund — Sunrise Education Network', 'equity'],
            ['3100', 'Accumulated Surplus', 'equity'],
            ['4000', 'Tuition Fee Income', 'income'],
            ['4100', 'Examination Fee Income', 'income'],
            ['4200', 'Admission Fee Income', 'income'],
            ['4300', 'Transport Fee Income', 'income'],
            ['4400', 'Library & Laboratory Fee Income', 'income'],
            ['4500', 'Donations & Sponsorships', 'income'],
            ['4900', 'Other Fee Income', 'income'],
            ['5000', 'Staff Salaries & Wages', 'expense'],
            ['5100', 'Rent — Building', 'expense'],
            ['5200', 'Utilities — Electricity, Gas & Water', 'expense'],
            ['5300', 'Stationery & Printing', 'expense'],
            ['5400', 'Maintenance & Repairs', 'expense'],
            ['5500', 'Security Services', 'expense'],
            ['5600', 'Transport & Fuel', 'expense'],
            ['5700', 'Events, Sports & Activities', 'expense'],
            ['5800', 'Internet & Telephone', 'expense'],
            ['5900', 'Miscellaneous Expenses', 'expense'],
        ];

        $ids = [];
        foreach ($chart as [$code, $name, $type]) {
            $ids[$code] = Account::firstOrCreate(
                ['organization_id' => 1, 'code' => $code],
                ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true],
            )->id;
        }

        $ledger = app(Ledger::class);
        $line = fn (string $code, float $debit, float $credit, ?string $memo = null) => [
            'account_id' => $ids[$code], 'debit' => $debit, 'credit' => $credit, 'memo' => $memo,
        ];

        $vouchers = [
            // [key, campus, date, type, narrative, lines]
            ['opening-2026', $lhr, '2026-04-01', 'journal', 'Opening balances — 2026-27 academic year', [
                $line('1000', 800000, 0, 'Campus cash float'),
                $line('1010', 2500000, 0, 'Meezan current'),
                $line('1200', 1200000, 0, 'Furniture & fixtures'),
                $line('1300', 800000, 0, 'IT & lab equipment'),
                $line('3000', 0, 5300000, 'Network fund'),
            ]],
            ['rent-lhr-sep', $lhr, '2026-09-05', 'payment', 'Building rent — September 2026', [
                $line('5100', 350000, 0), $line('1010', 0, 350000, 'Cheque'),
            ]],
            ['rent-lhr-oct', $lhr, '2026-10-05', 'payment', 'Building rent — October 2026', [
                $line('5100', 350000, 0), $line('1010', 0, 350000, 'Cheque'),
            ]],
            ['rent-isb-oct', $isb, '2026-10-07', 'payment', 'Building rent — October 2026', [
                $line('5100', 280000, 0), $line('1010', 0, 280000, 'Transfer'),
            ]],
            ['utilities-lhr-oct', $lhr, '2026-10-12', 'payment', 'LESCO electricity + SNGPL gas — October', [
                $line('5200', 92400, 0), $line('1010', 0, 92400, 'Bill'),
            ]],
            ['internet-lhr-oct', $lhr, '2026-10-12', 'payment', 'Stormfiber broadband + PTCL — October', [
                $line('5800', 45000, 0), $line('1010', 0, 45000, 'Bill'),
            ]],
            ['security-lhr-oct', $lhr, '2026-10-14', 'payment', 'Elite Security guard contract — October', [
                $line('5500', 120000, 0), $line('1010', 0, 120000, 'Transfer'),
            ]],
            ['stationery-lhr-oct', $lhr, '2026-10-15', 'payment', 'Question papers, registers & printing', [
                $line('5300', 18750, 0), $line('1000', 0, 18750, 'Cash purchase'),
            ]],
            ['maintenance-lhr-oct', $lhr, '2026-10-16', 'payment', 'Classroom fans, plumbing and paint work', [
                $line('5400', 63500, 0), $line('1000', 0, 63500, 'Cash'),
            ]],
            ['events-lhr-oct', $lhr, '2026-10-17', 'payment', 'House sports week — trophies and refreshments', [
                $line('5700', 85000, 0), $line('1010', 0, 85000, 'Transfer'),
            ]],
            ['fuel-lhr-oct', $lhr, '2026-10-18', 'payment', 'School van fuel — October', [
                $line('5600', 37200, 0), $line('1000', 0, 37200, 'Cash'),
            ]],
            ['donation-lhr-oct', $lhr, '2026-10-18', 'receipt', 'Donation from Parents Association for the library', [
                $line('1010', 150000, 0, 'Transfer'), $line('4500', 0, 150000),
            ]],
        ];

        foreach ($vouchers as [$key, $campus, $date, $type, $narrative, $lines]) {
            if (JournalVoucher::withoutGlobalScopes()->where('source', 'seed')->where('source_ref', $key)->exists()) continue;
            $ledger->post([
                'organization_id' => 1, 'campus_id' => $campus->id,
                'voucher_date' => $date, 'type' => $type, 'narrative' => $narrative,
                'source' => 'seed', 'source_ref' => $key,
                'posted_by' => $accountsOfficer->id, 'lines' => $lines,
            ]);
        }
    }
}
