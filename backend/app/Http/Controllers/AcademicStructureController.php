<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicStructureController extends Controller
{
    public function years(): JsonResponse
    {
        return response()->json(['data' => AcademicYear::orderByDesc('start_date')->get()]);
    }

    public function programs(): JsonResponse
    {
        return response()->json(['data' => Program::with('classes')->orderBy('name')->get()]);
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:16',
            'level' => ['required', Rule::in(['primary', 'middle', 'matric', 'intermediate', 'undergrad'])],
            'board' => 'nullable|string|max:32',
            'duration_years' => 'nullable|integer|min:1|max:6',
        ]);
        return response()->json(['data' => Program::create($data)], 201);
    }

    public function updateProgram(Request $request, Program $program): JsonResponse
    {
        $program->update($request->validate([
            'name' => 'sometimes|required|string|max:120',
            'code' => 'sometimes|required|string|max:16',
            'level' => ['sometimes', 'required', Rule::in(['primary', 'middle', 'matric', 'intermediate', 'undergrad'])],
            'board' => 'nullable|string|max:32',
            'duration_years' => 'sometimes|nullable|integer|min:1|max:6',
            'is_active' => 'sometimes|boolean',
        ]));
        return response()->json(['data' => $program->fresh()]);
    }

    public function classes(): JsonResponse
    {
        return response()->json(['data' => SchoolClass::with(['program', 'sections', 'subjects'])->get()]);
    }

    public function storeClass(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_id' => 'required|exists:programs,id',
            'name' => 'required|string|max:60',
            'level_no' => 'nullable|integer|min:1|max:16',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'campus_id' => 'nullable|exists:campuses,id',
        ]);
        return response()->json(['data' => SchoolClass::create($data)], 201);
    }

    public function updateClass(Request $request, SchoolClass $class): JsonResponse
    {
        $class->update($request->validate([
            'program_id' => 'sometimes|required|exists:programs,id',
            'name' => 'sometimes|required|string|max:60',
            'level_no' => 'sometimes|nullable|integer|min:1|max:16',
            'academic_year_id' => 'sometimes|nullable|exists:academic_years,id',
            'campus_id' => 'sometimes|nullable|exists:campuses,id',
        ]));
        return response()->json(['data' => $class->fresh(['program', 'sections', 'subjects'])]);
    }

    public function attachSubjects(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'subjects' => 'required|array',
            'subjects.*.subject_id' => 'required|exists:subjects,id',
            'subjects.*.is_compulsory' => 'boolean',
        ]);
        $sync = [];
        foreach ($data['subjects'] as $s) {
            $sync[$s['subject_id']] = ['is_compulsory' => $s['is_compulsory'] ?? false];
        }
        $class->subjects()->sync($sync);
        return response()->json(['data' => $class->load('subjects')]);
    }

    public function storeSection(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:16',
            'capacity' => 'nullable|integer|min:5|max:200',
            'class_teacher_id' => 'nullable|exists:users,id',
        ]);
        $section = $class->sections()->create($data + [
            'organization_id' => $class->organization_id,
            'campus_id' => $class->campus_id,
        ]);
        return response()->json(['data' => $section], 201);
    }

    public function updateSection(Request $request, Section $section): JsonResponse
    {
        $section->update($request->validate([
            'name' => 'sometimes|required|string|max:16',
            'capacity' => 'sometimes|nullable|integer|min:5|max:200',
            'class_teacher_id' => 'sometimes|nullable|exists:users,id',
        ]));
        return response()->json(['data' => $section->fresh(['schoolClass.program', 'classTeacher'])]);
    }

    public function sections(): JsonResponse
    {
        return response()->json(['data' => Section::with(['schoolClass.program', 'classTeacher:id,name'])->withCount('students')->get()]);
    }

    public function subjects(): JsonResponse
    {
        return response()->json(['data' => Subject::orderBy('name')->get()]);
    }

    public function storeSubject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:16',
            'group' => 'nullable|string|max:32',
            'credit_hours' => 'nullable|integer|min:1|max:200',
        ]);
        return response()->json(['data' => Subject::create($data)], 201);
    }

    public function updateSubject(Request $request, Subject $subject): JsonResponse
    {
        $subject->update($request->validate([
            'name' => 'sometimes|required|string|max:120',
            'code' => 'sometimes|required|string|max:16',
            'group' => 'sometimes|nullable|string|max:32',
            'credit_hours' => 'sometimes|nullable|integer|min:1|max:200',
        ]));
        return response()->json(['data' => $subject->fresh()]);
    }
}
