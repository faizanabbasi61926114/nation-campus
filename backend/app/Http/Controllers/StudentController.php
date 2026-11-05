<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Student::with(['schoolClass', 'section', 'program']);
        foreach (['class_id', 'section_id', 'program_id', 'status'] as $f) {
            if ($request->filled($f)) $q->where($f, $request->input($f));
        }
        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(fn ($w) => $w->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")
                ->orWhere('admission_no', 'like', "%$s%")->orWhere('roll_no', 'like', "%$s%")->orWhere('father_name', 'like', "%$s%"));
        }
        return response()->json($q->orderBy('last_name')->paginate(min(100, (int) $request->input('per_page', 25))));
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json(['data' => $student->load(['schoolClass.program', 'section', 'documents', 'admission'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'gender' => ['required', 'in:male,female,other'],
            'dob' => 'required|date|before:today',
            'father_name' => 'required|string|max:120',
            'mother_name' => 'nullable|string|max:120',
            'cnic_bform' => 'nullable|string|max:20',
            'guardian_name' => 'nullable|string|max:120',
            'guardian_phone' => 'nullable|string|max:24',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:64',
            'class_id' => 'nullable|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'program_id' => 'nullable|exists:programs,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'roll_no' => 'nullable|string|max:32',
            'status' => 'nullable|in:active,inactive,graduated,transferred,suspended',
        ]);
        $data['admission_no'] = $this->nextAdmissionNo($ctx->campus?->code ?? 'GEN');
        $data['campus_id'] = $ctx->campusId();
        $student = Student::create($data);
        return response()->json(['data' => $student], 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:120',
            'last_name' => 'sometimes|required|string|max:120',
            'gender' => ['sometimes', 'required', 'in:male,female,other'],
            'dob' => 'sometimes|required|date|before:today',
            'father_name' => 'sometimes|required|string|max:120',
            'mother_name' => 'nullable|string|max:120',
            'cnic_bform' => 'nullable|string|max:20',
            'guardian_name' => 'nullable|string|max:120',
            'guardian_phone' => 'nullable|string|max:24',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:64',
            'class_id' => 'nullable|exists:classes,id', 'section_id' => 'nullable|exists:sections,id',
            'program_id' => 'nullable|exists:programs,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'roll_no' => 'nullable|string|max:32',
            'status' => 'sometimes|in:active,inactive,graduated,transferred,suspended',
            'notes' => 'nullable|string',
        ]);
        $student->update($data);
        return response()->json(['data' => $student]);
    }

    public function promote(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
        ]);
        $student->update($data + ['status' => 'active']);
        return response()->json(['data' => $student]);
    }

    private function nextAdmissionNo(string $code): string
    {
        $n = (int) Student::max('id') + 1;
        return sprintf('S-%s-%s-%04d', $code, date('Y'), $n);
    }
}
