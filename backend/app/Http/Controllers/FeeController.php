<?php

namespace App\Http\Controllers;

use App\Models\FeeInvoice;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\FeeWaiver;
use App\Models\JournalVoucher;
use App\Models\Setting;
use App\Models\Student;
use App\Support\Ledger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeeController extends Controller
{
    public function __construct(private Ledger $ledger) {}
    public function structures(): JsonResponse
    {
        return response()->json(['data' => FeeStructure::with('class')->orderBy('name')->get()]);
    }

    public function storeStructure(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus first.'], 422);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => 'required|string|max:30',
            'amount' => 'required|numeric|min:1',
            'gst_percent' => 'nullable|numeric|min:0|max:100',
            'frequency' => ['required', 'in:one_time,monthly,quarterly,annual'],
            'due_day' => 'nullable|integer|min:1|max:28',
            'class_id' => 'nullable|exists:classes,id',
            'program_id' => 'nullable|exists:programs,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);
        $s = FeeStructure::create($data + ['organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId()]);
        return response()->json(['data' => $s], 201);
    }

    public function updateStructure(Request $request, FeeStructure $structure): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'category' => 'sometimes|required|string|max:30',
            'amount' => 'sometimes|required|numeric|min:1',
            'gst_percent' => 'nullable|numeric|min:0|max:100',
            'frequency' => ['sometimes', 'required', 'in:one_time,monthly,quarterly,annual'],
            'due_day' => 'nullable|integer|min:1|max:28',
            'class_id' => 'sometimes|nullable|exists:classes,id',
            'program_id' => 'sometimes|nullable|exists:programs,id',
            'academic_year_id' => 'sometimes|nullable|exists:academic_years,id',
            'is_active' => 'sometimes|boolean',
        ]);
        $structure->update($data);
        return response()->json(['data' => $structure->fresh()]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => 'nullable|integer',
            'status' => 'nullable|in:unpaid,partial,paid,waived,cancelled,overdue',
            'session_month' => 'nullable|date_format:Y-m',
        ]);
        $q = FeeInvoice::with(['student:id,first_name,last_name,roll_no', 'payments']);
        if ($data['student_id'] ?? null) $q->where('student_id', $data['student_id']);
        if ($data['session_month'] ?? null) $q->where('session_month', $data['session_month']);
        if (($data['status'] ?? null) === 'overdue') {
            $q->whereDate('due_date', '<', now()->toDateString())->whereIn('status', ['unpaid', 'partial']);
        } elseif ($data['status'] ?? null) {
            $q->where('status', $data['status']);
        }
        $invoices = $q->latest('issue_date')->paginate(min(100, (int) $request->input('per_page', 25)));
        $invoices->getCollection()->transform(fn ($i) => $i->append('balance')->setVisible([
            'id', 'student_id', 'title', 'amount', 'gst_amount', 'discount', 'late_fee', 'status',
            'issue_date', 'due_date', 'session_month', 'balance', 'student',
        ]));

        $sums = FeeInvoice::query()
            ->selectRaw('sum(amount + gst_amount - discount + late_fee) as billed')
            ->selectRaw('sum((select sum(p.amount) from fee_payments p where p.invoice_id = fee_invoices.id and p.status = \'cleared\')) as collected')
            ->first();
        return response()->json([
            'data' => $invoices,
            'summary' => [
                'billed' => (float) ($sums->billed ?? 0),
                'collected' => (float) ($sums->collected ?? 0),
                'outstanding' => max(0, (float) ($sums->billed ?? 0) - (float) ($sums->collected ?? 0)),
            ],
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus first.'], 422);
        $data = $request->validate([
            'fee_structure_id' => 'required|exists:fee_structures,id',
            'session_month' => ['required', 'date_format:Y-m'],
            'class_id' => 'nullable|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id',
        ]);
        $structure = FeeStructure::findOrFail($data['fee_structure_id']);
        $students = Student::where('campus_id', $ctx->campusId())->where('status', 'active')
            ->when($data['class_id'] ?? null, fn ($q) => $q->where('class_id', $data['class_id']))
            ->when($data['section_id'] ?? null, fn ($q) => $q->where('section_id', $data['section_id']))
            ->get(['id']);

        [$y, $m] = explode('-', $data['session_month']);
        $dueDay = $structure->due_day ?: (int) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'fees.due_day') ?: 10);
        $dueDate = sprintf('%s-%s-%02d', $y, $m, min($dueDay, cal_days_in_month(CAL_GREGORIAN, (int) $m, (int) $y)));

        $created = 0;
        $ids = [];
        foreach ($students as $s) {
            $exists = FeeInvoice::where('student_id', $s->id)->where('fee_structure_id', $structure->id)->where('session_month', $data['session_month'])->exists();
            if ($exists) continue;
            $invoice = FeeInvoice::create([
                'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
                'student_id' => $s->id, 'fee_structure_id' => $structure->id,
                'title' => $structure->name, 'amount' => $structure->amount,
                'gst_amount' => round($structure->amount * $structure->gst_percent / 100, 2),
                'issue_date' => date('Y-m-01'), 'due_date' => $dueDate,
                'session_month' => $data['session_month'], 'status' => 'unpaid',
            ]);
            $ids[] = $invoice->id;
            $created++;
        }
        return response()->json([
            'message' => "Generated $created invoice(s) for {$structure->name} (" . $data['session_month'] . ').',
            'data' => ['created' => $created, 'invoice_ids' => $ids],
        ]);
    }

    public function showInvoice(FeeInvoice $invoice): JsonResponse
    {
        return response()->json(['data' => $invoice->load(['student', 'payments.receiver'])
            ->append('balance')->setVisible([
                'id', 'student_id', 'title', 'amount', 'gst_amount', 'discount', 'late_fee', 'status',
                'issue_date', 'due_date', 'session_month', 'balance', 'student', 'payments',
            ])]);
    }

    public function recordPayment(Request $request, FeeInvoice $invoice): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'channel' => ['required', 'in:cash,bank_transfer,jazzcash,easypaisa,cheque'],
            'reference' => 'nullable|string|max:60',
            'paid_on' => 'nullable|date|before_or_equal:today',
            'remarks' => 'nullable|string|max:255',
        ]);
        if (in_array($invoice->status, ['cancelled', 'waived'], true)) {
            return response()->json(['message' => 'Invoice is ' . $invoice->status . '.'], 422);
        }
        $balance = $invoice->append('balance')->balance;
        if ($data['amount'] > $balance + 0.001) {
            return response()->json(['message' => "Payment exceeds outstanding balance of Rs {$balance}."], 422);
        }
        $campusCode = optional($ctx->campus)->code ?? 'GEN';
        $receipt = sprintf('RCP-%s-%s-%05d', $campusCode, now()->format('Ym'), random_int(0, 99999));

        DB::transaction(function () use ($data, $invoice, $ctx, $receipt, $request) {
            $payment = FeePayment::create([
                'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
                'invoice_id' => $invoice->id, 'receipt_no' => $receipt,
                'amount' => $data['amount'], 'channel' => $data['channel'],
                'reference' => $data['reference'] ?? null, 'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'received_by' => $request->user()->id, 'status' => 'cleared',
                'remarks' => $data['remarks'] ?? null,
            ]);
            $this->syncInvoiceStatus($invoice);
            $this->ledger->postFeePayment($payment);
        });
        return response()->json(['data' => $invoice->fresh()->load('payments')->append('balance')->setVisible([
            'id', 'title', 'amount', 'gst_amount', 'discount', 'late_fee', 'status', 'balance', 'payments',
        ])], 201);
    }

    public function reversePayment(Request $request, FeePayment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        if ($payment->status === 'reversed') return response()->json(['message' => 'Already reversed.'], 422);
        $payment->update(['status' => 'reversed', 'remarks' => trim(($payment->remarks ? $payment->remarks . ' | ' : '') . 'Reversed: ' . $data['reason'])]);
        $this->ledger->void(JournalVoucher::where('source', 'fee_payment')->where('source_ref', $payment->receipt_no)->first());
        $this->syncInvoiceStatus($payment->invoice);
        return response()->json(['message' => 'Payment reversed.']);
    }

    public function applyLateFees(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus first.'], 422);
        $percent = (float) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'fees.late_fee_percent') ?: 0);
        if ($percent <= 0) return response()->json(['message' => 'Late fee percent is 0 for this campus.']);

        $overdue = FeeInvoice::query()
            ->select('fee_invoices.*')
            ->leftJoin('fee_waivers', function ($j) {
                $j->on('fee_waivers.invoice_id', '=', 'fee_invoices.id')
                    ->whereIn('fee_waivers.status', ['pending', 'approved']);
            })
            ->whereIn('fee_invoices.status', ['unpaid', 'partial'])
            ->whereDate('fee_invoices.due_date', '<', now()->toDateString())
            ->where('fee_invoices.late_fee', 0)
            ->whereNull('fee_waivers.id')
            ->get();
        $n = 0;
        foreach ($overdue as $inv) {
            $fee = round(($inv->amount + $inv->gst_amount - $inv->discount) * $percent / 100, 2);
            $inv->update(['late_fee' => $fee]);
            $n++;
        }
        return response()->json(['message' => "Applied Rs {$percent}% late fee to $n overdue invoice(s)."]);
    }

    public function waivers(Request $request): JsonResponse
    {
        return response()->json(['data' => FeeWaiver::with(['student:id,first_name,last_name,roll_no', 'invoice:id,title,amount'])
            ->latest()->limit(100)->get()]);
    }

    public function requestWaiver(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'student_id' => 'required|integer',
            'invoice_id' => 'nullable|integer',
            'percent' => 'nullable|required_without:amount|numeric|min:1|max:100',
            'amount' => 'nullable|required_without:percent|numeric|min:1',
            'reason' => 'required|string|max:255',
        ]);
        // Tenant-scoped lookups — a foreign organisation's ids must not be waivable.
        if (!Student::whereKey($data['student_id'])->exists()) {
            return response()->json(['message' => 'Student does not exist in your campus.'], 422);
        }
        if (($data['invoice_id'] ?? null) && !FeeInvoice::whereKey($data['invoice_id'])->exists()) {
            return response()->json(['message' => 'Invoice does not exist in your campus.'], 422);
        }
        $waiver = FeeWaiver::create($data + [
            'organization_id' => $ctx->organizationId, 'campus_id' => $ctx->campusId(),
            'status' => 'pending', 'requested_by' => $request->user()->id,
        ]);
        return response()->json(['data' => $waiver], 201);
    }

    public function decideWaiver(Request $request, FeeWaiver $waiver): JsonResponse
    {
        if ($waiver->status !== 'pending') return response()->json(['message' => 'Waiver already decided.'], 422);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);
        $waiver->update(['status' => $data['decision'], 'approved_by' => $request->user()->id]);

        if ($data['decision'] === 'approved' && $waiver->invoice_id) {
            $invoice = FeeInvoice::find($waiver->invoice_id);
            if ($invoice) {
                $gross = $invoice->amount + $invoice->gst_amount;
                $discount = $waiver->percent ? round($gross * $waiver->percent / 100, 2) : min((float) $waiver->amount, $gross);
                $invoice->update(['discount' => (float) $invoice->discount + $discount]);
                $this->syncInvoiceStatus($invoice);
            }
        }
        return response()->json(['data' => $waiver->fresh()]);
    }

    public function outstanding(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $rows = FeeInvoice::with('student:id,first_name,last_name,roll_no')
            ->whereIn('status', ['unpaid', 'partial'])
            ->get()->groupBy('student_id')
            ->map(function ($invs, $sid) {
                $student = $invs->first()->student;
                return [
                    'student_id' => $sid,
                    'name' => $student ? trim($student->first_name . ' ' . $student->last_name) : "#$sid",
                    'roll_no' => $student?->roll_no,
                    'invoices' => $invs->count(),
                    'overdue' => $invs->where('due_date', '<', now()->toDateString())->count(),
                    'outstanding' => round($invs->sum(fn ($i) => $i->append('balance')->balance), 2),
                ];
            })
            ->sortByDesc('outstanding')->values();
        return response()->json(['data' => $rows]);
    }

    private function syncInvoiceStatus(FeeInvoice $invoice): void
    {
        $invoice->load('payments');
        $due = $invoice->totalDue();
        $paid = $invoice->paidAmount();
        $status = match (true) {
            $invoice->status === 'cancelled' => 'cancelled',
            $due <= 0 => 'waived',
            $paid >= $due - 0.001 => 'paid',
            $paid > 0 => 'partial',
            default => 'unpaid',
        };
        $invoice->update(['status' => $status]);
    }
}
