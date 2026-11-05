<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $campuses = Campus::whereIn('id', $user->accessibleCampusIds())
            ->where('organization_id', $user->organization_id)
            ->orderBy('name')->get();
        return response()->json(['data' => $campuses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:16', 'distinct'],
            'school_type' => ['nullable', Rule::in(config('cms.school_types'))],
            'city' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:24'],
            'is_head_office' => ['boolean'],
            'initial_user' => ['sometimes', 'array'],
            'initial_user.name' => ['required_with:initial_user', 'string', 'max:120'],
            'initial_user.email' => ['required_with:initial_user', 'email', Rule::unique('users', 'email')],
            'initial_user.password' => ['required_with:initial_user', 'string', 'min:' . config('cms.password_min')],
        ]);

        $initialUser = $data['initial_user'] ?? null;
        unset($data['initial_user']);
        $data['school_type'] = $data['school_type'] ?? 'secondary';
        $data['organization_id'] = $request->user()->organization_id;

        $principalRole = null;
        if ($initialUser) {
            $principalRole = Role::where('organization_id', $request->user()->organization_id)
                ->where('slug', 'principal')
                ->first();

            if (!$principalRole) {
                throw ValidationException::withMessages([
                    'initial_user' => 'The Campus Principal role is not configured for this organization.',
                ]);
            }
        }

        $campus = DB::transaction(function () use ($data, $initialUser, $principalRole) {
            $campus = Campus::create($data);

            if ($initialUser) {
                $user = User::create([
                    'organization_id' => $campus->organization_id,
                    'campus_id' => $campus->id,
                    'name' => $initialUser['name'],
                    'email' => $initialUser['email'],
                    'password' => $initialUser['password'],
                    'is_active' => true,
                ]);
                $user->roleAssignments()->attach($principalRole->id, ['campus_id' => $campus->id]);
            }

            return $campus;
        });

        return response()->json(['data' => $campus], 201);
    }

    public function update(Request $request, Campus $campus): JsonResponse
    {
        abort_unless($campus->organization_id === $request->user()->organization_id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'required', 'string', 'max:16', Rule::unique('campuses', 'code')->where('organization_id', $campus->organization_id)->ignore($campus->id)],
            'school_type' => ['sometimes', 'required', Rule::in(config('cms.school_types'))],
            'city' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:24'],
            'is_active' => ['boolean'],
        ]);
        $campus->update($data);
        return response()->json(['data' => $campus]);
    }
}
