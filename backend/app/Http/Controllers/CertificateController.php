<?php

namespace App\Http\Controllers;

use App\Models\CertificateRequest;
use App\Models\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Certificates and documents: a request is raised by the front desk or a class teacher,
 * decided by a principal, then issued with a serial number that is never reused.
 *
 * Workflow: pending → approved|rejected → issued.
 */
class CertificateController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function summary(): JsonResponse
    {
        $byStatus = CertificateRequest::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => [
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'approved' => (int) ($byStatus['approved'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
            'issued' => (int) ($byStatus['issued'] ?? 0),
            'awaiting_issue' => (int) ($byStatus['approved'] ?? 0),
            'issued_this_month' => CertificateRequest::where('status', 'issued')
                ->whereDate('issued_at', '>=', now()->startOfMonth())->count(),
            'by_type' => CertificateRequest::query()->selectRaw('type, count(*) c')
                ->groupBy('type')->pluck('c', 'type')->map(fn ($c) => (int) $c),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', CertificateRequest::STATUSES)],
            'type' => ['nullable', 'in:' . implode(',', CertificateRequest::TYPES)],
            'student_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        $rows = CertificateRequest::with([
            'student:id,first_name,last_name,admission_no,class_id,section_id,campus_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'campus:id,name,code',
            'requester:id,name',
            'decider:id,name',
        ])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['student_id'] ?? null, fn ($q, $i) => $q->where('student_id', $i))
            ->when($data['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('reference_no', 'like', "%$s%")
                    ->orWhere('purpose', 'like', "%$s%")
                    ->orWhereHas('student', fn ($st) => $st->where('first_name', 'like', "%$s%")
                        ->orWhere('last_name', 'like', "%$s%")->orWhere('admission_no', 'like', "%$s%")));
            })
            ->orderByRaw("case status when 'pending' then 0 when 'approved' then 1 when 'issued' then 2 else 3 end")
            ->latest('id')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->ctx->campusId()) {
            return response()->json(['message' => 'Pick a campus before raising a certificate request.'], 422);
        }

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'type' => ['required', 'in:' . implode(',', CertificateRequest::TYPES)],
            'title' => ['nullable', 'string', 'max:160'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'body' => ['nullable', 'string', 'max:4000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = Student::find($data['student_id']);
        if (!$student) {
            return response()->json(['message' => 'That student is not in your campus.'], 422);
        }

        $row = CertificateRequest::create($data + [
            'organization_id' => $this->ctx->organizationId,
            'campus_id' => $student->campus_id ?: $this->ctx->campusId(),
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($row->fresh())], 201);
    }

    public function update(Request $request, CertificateRequest $certificate): JsonResponse
    {
        if ($certificate->isDecided()) {
            return response()->json([
                'message' => 'This request has already been ' . $certificate->status . ', so its details are locked. Raise a new request instead.',
            ], 422);
        }

        $data = $request->validate([
            'type' => ['sometimes', 'required', 'in:' . implode(',', CertificateRequest::TYPES)],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'body' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $certificate->update($data);

        return response()->json(['data' => $this->present($certificate->fresh())]);
    }

    /** pending → approved|rejected. A decision is final; correcting one means a fresh request. */
    public function decide(Request $request, CertificateRequest $certificate): JsonResponse
    {
        if ($certificate->isDecided()) {
            return response()->json([
                'message' => 'This request has already been ' . $certificate->status . ' and cannot be decided twice.',
            ], 422);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $certificate->update([
            'status' => $data['decision'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'remarks' => $data['remarks'] ?? $certificate->remarks,
        ]);

        return response()->json([
            'data' => $this->present($certificate->fresh()),
            'message' => $data['decision'] === 'approved' ? 'Approved — it can now be issued.' : 'Request rejected.',
        ]);
    }

    /** approved → issued, minting the next serial number for the organization. */
    public function issue(Request $request, CertificateRequest $certificate): JsonResponse
    {
        if ($certificate->status === 'issued') {
            return response()->json(['message' => 'Already issued as ' . $certificate->reference_no . '.'], 422);
        }
        if ($certificate->status === 'rejected') {
            return response()->json(['message' => 'A rejected request cannot be issued. Approve it first by raising a new request.'], 422);
        }
        if ($certificate->status === 'pending') {
            return response()->json(['message' => 'Approve or reject this request before issuing the certificate.'], 422);
        }

        $certificate = $this->mintReference($certificate);

        return response()->json([
            'data' => $this->present($certificate),
            'message' => 'Certificate issued as ' . $certificate->reference_no . '.',
        ]);
    }

    /**
     * The printable wording is assembled from the student's live record, so a correction to
     * the student profile reaches every certificate printed afterwards.
     */
    public function printText(CertificateRequest $certificate): JsonResponse
    {
        $certificate->load(['student.schoolClass', 'student.section', 'campus:id,name,code']);
        $student = $certificate->student;
        $name = $certificate->studentName();
        $className = $student?->schoolClass?->name;
        $sectionName = $student?->section?->name;

        $enrolment = $className
            ? 'a bona fide student of ' . $className . ($sectionName ? ', Section ' . $sectionName : '')
            : 'a student of this institution';
        $purpose = trim((string) $certificate->purpose) ?: 'the purpose stated in the application';
        $typeLabel = ucwords(str_replace('_', ' ', (string) $certificate->type));
        $campusName = $certificate->campus?->name ?? $this->ctx->campus?->name ?? 'the school';
        $reference = $certificate->reference_no ?: 'to be allotted on issue';

        $header = trim((string) $certificate->title) ?: 'TO WHOM IT MAY CONCERN';
        $body = sprintf(
            'This is to certify that %s, admission number %s, %s, has been issued this %s Certificate by %s '
            . 'for the purpose of %s. Reference no. %s.',
            $name,
            $student?->admission_no ?? 'not on record',
            $enrolment,
            $typeLabel,
            $campusName,
            $purpose,
            $reference,
        );

        $paragraphs = array_filter([$header, $body, trim((string) $certificate->body)]);
        $footer = 'Dated ' . ($certificate->issued_at?->toDateString() ?? now()->toDateString())
            . ' — signed by the Administrator, ' . $campusName;

        return response()->json(['data' => [
            'header' => $header,
            'body' => $body,
            'text' => implode("\n\n", $paragraphs) . "\n\n" . $footer,
            'student_name' => $name,
            'admission_no' => $student?->admission_no,
            'class' => $className,
            'section' => $sectionName,
            'type' => $certificate->type,
            'purpose' => $certificate->purpose,
            'reference_no' => $certificate->reference_no,
            'campus_name' => $campusName,
            'issued_at' => $certificate->issued_at?->toDateTimeString(),
            'printable' => (bool) $certificate->reference_no,
        ]]);
    }

    // ------------------------------------------------------------------------ helpers

    private function present(CertificateRequest $certificate): CertificateRequest
    {
        return $certificate->load([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'campus:id,name,code',
            'requester:id,name',
            'decider:id,name',
        ]);
    }

    /**
     * CERT/<campus code>/<sequence>, numbered from one sequence per organization so the
     * unique (organization_id, reference_no) index can never be violated by a second campus.
     * Counting and stamping share one transaction, and a collision retries.
     */
    private function mintReference(CertificateRequest $certificate): CertificateRequest
    {
        $code = $certificate->campus?->code ?? $this->ctx->campus?->code ?? 'HO';

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($certificate, $code) {
                    $highest = CertificateRequest::withoutGlobalScopes()
                        ->where('organization_id', $certificate->organization_id)
                        ->whereNotNull('reference_no')
                        ->pluck('reference_no')
                        ->reduce(fn ($max, $ref) => max((int) $max, CertificateRequest::serialOf((string) $ref)), 0);

                    $certificate->update([
                        'reference_no' => sprintf('CERT/%s/%04d', $code, $highest + 1),
                        'status' => 'issued',
                        'issued_at' => now(),
                    ]);

                    return $certificate->fresh();
                });
            } catch (UniqueConstraintViolationException) {
                if ($attempt >= 3) {
                    throw ValidationException::withMessages([
                        'reference_no' => 'The certificate serial number could not be allocated. Please try again.',
                    ]);
                }
            }
        }
    }
}
