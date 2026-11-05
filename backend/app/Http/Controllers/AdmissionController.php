<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdmissionController extends Controller
{
    private const TRANSITIONS = [
        'pending' => ['screening', 'rejected', 'withdrawn'],
        'screening' => ['interview', 'test', 'rejected', 'waitlisted', 'withdrawn'],
        'interview' => ['test', 'offered', 'rejected', 'waitlisted', 'withdrawn'],
        'test' => ['offered', 'rejected', 'waitlisted', 'withdrawn'],
        'offered' => ['accepted', 'rejected', 'withdrawn'],
        'accepted' => ['enrolled', 'withdrawn'],
        'waitlisted' => ['offered', 'rejected', 'withdrawn'],
        'enrolled' => [],
        'rejected' => ['screening'],
        'withdrawn' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        $q = Admission::with(['program', 'schoolClass']);
        if ($request->filled('status')) $q->where('status', $request->input('status'));
        if ($request->filled('program_id')) $q->where('program_id', $request->integer('program_id'));
        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(fn ($w) => $w->where('applicant_name', 'like', "%$s%")->orWhere('application_no', 'like', "%$s%")->orWhere('phone', 'like', "%$s%"));
        }
        return response()->json($q->latest()->paginate(min(100, (int) $request->input('per_page', 25))));
    }

    public function show(Admission $admission): JsonResponse
    {
        return response()->json(['data' => $admission->load(['program', 'schoolClass', 'student'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'applicant_name' => 'required|string|max:120',
            'gender' => ['required', 'in:male,female,other'],
            'dob' => 'required|date|before:today',
            'father_name' => 'required|string|max:120',
            'mother_name' => 'nullable|string|max:120',
            'cnic_bform' => 'nullable|string|max:20',
            'phone' => ['required', 'string', 'regex:' . config('cms.phone_pattern')],
            'previous_school' => 'nullable|string|max:160',
            'previous_marks' => 'nullable|integer|min:0|max:100',
            'program_id' => 'required|exists:programs,id',
            'class_id' => 'nullable|exists:classes,id',
            'remarks' => 'nullable|string',
        ]);
        $data['campus_id'] = $ctx->campusId() ?: $request->integer('campus_id');
        abort_unless($data['campus_id'], 422, 'Select a campus (or switch the campus context first).');
        $data['application_no'] = sprintf('ADM-%s-%04d', date('Y'), (int) Admission::max('id') + 1);
        $admission = Admission::create($data);
        return response()->json(['data' => $admission], 201);
    }

    public function update(Request $request, Admission $admission): JsonResponse
    {
        if (in_array($admission->status, ['enrolled', 'rejected', 'withdrawn'], true)) {
            return response()->json(['message' => 'This application is closed and can no longer be edited.'], 422);
        }

        $admission->update($request->validate([
            'applicant_name' => 'sometimes|required|string|max:120',
            'gender' => ['sometimes', 'required', 'in:male,female,other'],
            'dob' => 'sometimes|required|date|before:today',
            'father_name' => 'sometimes|required|string|max:120',
            'mother_name' => 'sometimes|nullable|string|max:120',
            'cnic_bform' => 'sometimes|nullable|string|max:20',
            'phone' => ['sometimes', 'required', 'string', 'regex:' . config('cms.phone_pattern')],
            'previous_school' => 'sometimes|nullable|string|max:160',
            'previous_marks' => 'sometimes|nullable|integer|min:0|max:100',
            'program_id' => 'sometimes|required|exists:programs,id',
            'class_id' => 'sometimes|nullable|exists:classes,id',
            'remarks' => 'sometimes|nullable|string',
        ]));

        return response()->json(['data' => $admission->fresh(['program', 'schoolClass'])]);
    }

    public function transition(Request $request, Admission $admission): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'in:' . implode(',', array_keys(self::TRANSITIONS))],
            'remarks' => 'nullable|string',
        ]);
        $allowed = self::TRANSITIONS[$admission->status] ?? [];
        if (!in_array($data['to'], $allowed, true)) {
            return response()->json(['message' => "Cannot move application from {$admission->status} to {$data['to']}."], 422);
        }
        $admission->update(['status' => $data['to'], 'remarks' => $data['remarks'] ?? $admission->remarks, 'decision_by' => $request->user()->id, 'decided_at' => now()]);
        return response()->json(['data' => $admission]);
    }

    public function enroll(Request $request, Admission $admission): JsonResponse
    {
        if ($admission->status !== 'accepted') {
            return response()->json(['message' => 'Only accepted applications can be enrolled.'], 422);
        }
        $data = $request->validate([
            'section_id' => 'nullable|exists:sections,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'roll_no' => 'nullable|string|max:32',
        ]);
        $ctx = app(TenantContext::class);
        $student = Student::create([
            'campus_id' => $admission->campus_id,
            'admission_no' => sprintf('S-%s-%s-%04d', $ctx->campus?->code ?? 'GEN', date('Y'), (int) Student::max('id') + 1),
            'first_name' => strtok($admission->applicant_name, ' ') ?: $admission->applicant_name,
            'last_name' => trim(substr($admission->applicant_name, strlen(strtok($admission->applicant_name, ' ')))) ?: '-',
            'gender' => $admission->gender,
            'dob' => $admission->dob,
            'father_name' => $admission->father_name,
            'mother_name' => $admission->mother_name,
            'cnic_bform' => $admission->cnic_bform,
            'guardian_phone' => $admission->phone,
            'class_id' => $admission->class_id,
            'program_id' => $admission->program_id,
            'admission_id' => $admission->id,
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ] + $data);
        $admission->update(['status' => 'enrolled']);
        AuditLog::record($admission, 'enrolled', "Application {$admission->application_no} enrolled as student {$student->admission_no}");
        return response()->json(['data' => $student], 201);
    }
}
