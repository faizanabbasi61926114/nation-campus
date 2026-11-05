<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalVoucher;
use App\Support\Ledger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function __construct(private Ledger $ledger) {}

    public function chart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'type' => 'nullable|in:asset,liability,equity,income,expense',
        ]);
        $balances = $this->balances($data['from'] ?? null, $data['to'] ?? null);

        $accounts = Account::with('parent:id,code,name')
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderBy('code')
            ->get()
            ->map(function ($a) use ($balances) {
                $row = $balances->get($a->id);
                $debit = round((float) ($row->d ?? 0), 2);
                $credit = round((float) ($row->c ?? 0), 2);
                return [
                    'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                    'is_system' => (bool) $a->is_system, 'is_active' => (bool) $a->is_active,
                    'parent' => $a->parent?->code, 'debit' => $debit, 'credit' => $credit,
                    'balance' => $a->isDebitNormal() ? round($debit - $credit, 2) : round($credit - $debit, 2),
                ];
            });

        return response()->json(['data' => $accounts->values()]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:120',
            'type' => ['required', 'in:' . implode(',', Account::TYPES)],
            'parent_id' => 'nullable|exists:accounts,id',
            'campus_id' => 'nullable|integer',
        ]);
        $clash = Account::withoutGlobalScopes()->where('organization_id', $ctx->organizationId)->where('code', $data['code'])->exists();
        if ($clash) return response()->json(['message' => 'Account code ' . $data['code'] . ' is already used.'], 422);

        $account = Account::create($data + ['organization_id' => $ctx->organizationId, 'campus_id' => $data['campus_id'] ?? $ctx->campusId()]);
        return response()->json(['data' => $account], 201);
    }

    public function updateAccount(Request $request, Account $account): JsonResponse
    {
        $account->update($request->validate([
            'name' => 'sometimes|required|string|max:120',
            'is_active' => 'sometimes|boolean',
        ]));
        return response()->json(['data' => $account->fresh()]);
    }

    public function vouchers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'type' => 'nullable|in:' . implode(',', JournalVoucher::TYPES),
            'source' => 'nullable|string|max:20',
        ]);
        $vouchers = JournalVoucher::with(['lines.account:id,code,name', 'campus:id,name,code'])
            ->when($data['from'] ?? null, fn ($q, $f) => $q->whereDate('voucher_date', '>=', $f))
            ->when($data['to'] ?? null, fn ($q, $t) => $q->whereDate('voucher_date', '<=', $t))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->latest('voucher_date')->paginate(min(100, (int) $request->input('per_page', 25)));
        return response()->json(['data' => $vouchers]);
    }

    public function storeVoucher(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before posting a voucher.'], 422);

        $data = $request->validate([
            'voucher_date' => ['required', 'date', 'before_or_equal:today'],
            'type' => ['required', 'in:' . implode(',', JournalVoucher::TYPES)],
            'narrative' => 'required|string|max:255',
            'remarks' => 'nullable|string|max:500',
            'lines' => 'required|array|min:2|max:30',
            'lines.*.account_id' => 'required|integer|exists:accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.memo' => 'nullable|string|max:160',
        ]);

        $lines = collect($data['lines'])->map(function ($l) use ($ctx) {
            $account = Account::find($l['account_id']);
            if (!$account) abort(422, 'Account ' . $l['account_id'] . ' is not available in your campus.');
            return [
                'account_id' => $account->id,
                'debit' => (float) ($l['debit'] ?? 0),
                'credit' => (float) ($l['credit'] ?? 0),
                'memo' => $l['memo'] ?? null,
            ];
        })->all();

        $debits = round(array_sum(array_column($lines, 'debit')), 2);
        $credits = round(array_sum(array_column($lines, 'credit')), 2);
        if ($debits <= 0 || abs($debits - $credits) > 0.009) {
            return response()->json(['message' => "Voucher is out of balance: debits Rs $debits vs credits Rs $credits."], 422);
        }

        $voucher = $this->ledger->post($data + [
            'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
            'source' => 'manual', 'posted_by' => $request->user()->id, 'lines' => $lines,
        ]);

        return response()->json(['data' => $voucher->load('lines.account:id,code,name')], 201);
    }

    public function reverseVoucher(Request $request, JournalVoucher $voucher): JsonResponse
    {
        if ($voucher->status !== 'posted') return response()->json(['message' => 'Voucher is already ' . $voucher->status . '.'], 422);
        $voucher->update(['status' => 'reversed', 'remarks' => trim(($voucher->remarks ? $voucher->remarks . ' | ' : '') . 'Reversed: ' . $request->input('reason', 'manual reversal'))]);
        return response()->json(['data' => $voucher->fresh(), 'message' => 'Voucher ' . $voucher->voucher_no . ' reversed.']);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $balances = $this->balances($data['from'] ?? null, $data['to'] ?? null);

        $rows = Account::orderBy('code')->get()->map(function ($a) use ($balances) {
            $row = $balances->get($a->id);
            $net = round((float) ($row->d ?? 0) - (float) ($row->c ?? 0), 2);
            if (abs($net) < 0.005) return null;
            return [
                'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                'debit' => $net > 0 ? $net : 0, 'credit' => $net < 0 ? -$net : 0,
            ];
        })->filter()->values();

        return response()->json(['data' => [
            'rows' => $rows,
            'totals' => ['debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2)],
        ]]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = $data['from'] ?? date('Y-01-01');
        $to = $data['to'] ?? date('Y-m-d');
        $balances = $this->balances($from, $to);

        $section = fn (string $type) => Account::where('type', $type)->orderBy('code')->get()
            ->map(function ($a) use ($balances) {
                $row = $balances->get($a->id);
                $net = $a->isDebitNormal()
                    ? round((float) ($row->d ?? 0) - (float) ($row->c ?? 0), 2)
                    : round((float) ($row->c ?? 0) - (float) ($row->d ?? 0), 2);
                return ['code' => $a->code, 'name' => $a->name, 'amount' => $net];
            })
            ->filter(fn ($r) => abs($r['amount']) > 0.004)->values();

        $income = $section('income');
        $expense = $section('expense');
        return response()->json(['data' => [
            'period' => ['from' => (string) $from, 'to' => (string) $to],
            'income' => $income, 'expense' => $expense,
            'total_income' => round($income->sum('amount'), 2),
            'total_expense' => round($expense->sum('amount'), 2),
            'net' => round($income->sum('amount') - $expense->sum('amount'), 2),
        ]]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $asOf = $request->validate(['as_of' => 'nullable|date'])['as_of'] ?? date('Y-m-d');
        $balances = $this->balances(null, $asOf);

        $group = function (array $types) use ($balances) {
            return Account::whereIn('type', $types)->orderBy('code')->get()->map(function ($a) use ($balances) {
                $row = $balances->get($a->id);
                $net = $a->isDebitNormal()
                    ? round((float) ($row->d ?? 0) - (float) ($row->c ?? 0), 2)
                    : round((float) ($row->c ?? 0) - (float) ($row->d ?? 0), 2);
                return ['code' => $a->code, 'name' => $a->name, 'amount' => $net];
            })->filter(fn ($r) => abs($r['amount']) > 0.004)->values();
        };

        $assets = $group(['asset']);
        $liabilities = $group(['liability']);
        $equity = $group(['equity']);
        // Current-year surplus = income minus expense, both already expressed in their normal direction.
        $surplus = round($group(['income'])->sum('amount') - $group(['expense'])->sum('amount'), 2);

        return response()->json(['data' => [
            'as_of' => (string) $asOf,
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'total_assets' => round($assets->sum('amount'), 2),
            'total_liabilities' => round($liabilities->sum('amount'), 2),
            'total_equity' => round($equity->sum('amount'), 2),
            'current_surplus' => round($surplus, 2),
            'balanced' => abs(round($assets->sum('amount'), 2) - round($liabilities->sum('amount') + $equity->sum('amount') + $surplus, 2)) < 0.01,
        ]]);
    }

    public function accountLedger(Request $request, Account $account): JsonResponse
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'limit' => 'nullable|integer|max:300']);
        $ctx = app(TenantContext::class);

        $rows = DB::table('journal_lines')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_lines.voucher_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_vouchers.organization_id', $ctx->organizationId)
            ->where('journal_vouchers.status', 'posted')
            ->when($ctx->campusId(), fn ($q) => $q->where('journal_vouchers.campus_id', $ctx->campusId()))
            ->when($data['from'] ?? null, fn ($q, $f) => $q->whereDate('journal_vouchers.voucher_date', '>=', $f))
            ->when($data['to'] ?? null, fn ($q, $t) => $q->whereDate('journal_vouchers.voucher_date', '<=', $t))
            ->orderBy('journal_vouchers.voucher_date')->orderBy('journal_vouchers.id')
            ->limit((int) ($data['limit'] ?? 200))
            ->get([
                'journal_vouchers.voucher_no', 'journal_vouchers.voucher_date', 'journal_vouchers.narrative',
                'journal_vouchers.type', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.memo',
            ]);

        $running = 0.0;
        $sign = $account->isDebitNormal() ? 1 : -1;
        $lines = $rows->map(function ($r) use (&$running, $sign) {
            $running += ((float) $r->debit - (float) $r->credit) * $sign;
            return [
                'voucher_no' => $r->voucher_no, 'date' => $r->voucher_date, 'narrative' => $r->narrative,
                'type' => $r->type, 'memo' => $r->memo,
                'debit' => (float) $r->debit, 'credit' => (float) $r->credit, 'balance' => round($running, 2),
            ];
        });

        return response()->json(['data' => [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'lines' => $lines,
            'totals' => ['debit' => round($lines->sum('debit'), 2), 'credit' => round($lines->sum('credit'), 2), 'closing' => round($running, 2)],
        ]]);
    }

    public function summary(): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $monthStart = date('Y-m-01');
        $balances = $this->balances($monthStart, date('Y-m-d'));
        $all = $this->balances(null, null);

        $byCode = Account::get()->keyBy('code');
        $cashCodes = ['1000', '1010', '1020', '1030'];
        $cash = collect($cashCodes)->sum(function ($code) use ($byCode, $all) {
            $row = $all->get((int) optional($byCode->get($code))->id);
            return $row ? (float) $row->d - (float) $row->c : 0.0;
        });

        $incomeIds = Account::where('type', 'income')->pluck('id');
        $expenseIds = Account::where('type', 'expense')->pluck('id');
        $income = $balances->only($incomeIds->all())->sum(fn ($r) => (float) $r->c - (float) $r->d);
        $expense = $balances->only($expenseIds->all())->sum(fn ($r) => (float) $r->d - (float) $r->c);

        return response()->json(['data' => [
            'cash_on_hand' => round($cash, 2),
            'month' => ['from' => $monthStart, 'to' => date('Y-m-d')],
            'income_this_month' => round($income, 2),
            'expense_this_month' => round($expense, 2),
            'net_this_month' => round($income - $expense, 2),
            'vouchers' => JournalVoucher::count(),
        ]]);
    }

    /** Posted debit/credit totals per account, honouring the active campus and an optional period. */
    private function balances(?string $from, ?string $to)
    {
        $ctx = app(TenantContext::class);
        return DB::table('journal_lines')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_lines.voucher_id')
            ->where('journal_vouchers.organization_id', $ctx->organizationId ?: 1)
            ->where('journal_vouchers.status', 'posted')
            ->when($ctx->campusId(), fn ($q) => $q->where('journal_vouchers.campus_id', $ctx->campusId()))
            ->when($from, fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '<=', $to))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as aid, sum(journal_lines.debit) as d, sum(journal_lines.credit) as c')
            ->get()
            ->keyBy('aid');
    }
}
