<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = User::with('roleAssignments')->where('organization_id', $user->organization_id);
        if ($request->filled('campus_id')) {
            $q->where('campus_id', $request->integer('campus_id'));
        }
        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        return response()->json($q->orderBy('name')->paginate(min(100, (int) $request->input('per_page', 25))));
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users')],
            'password' => ['required', 'string', 'min:' . config('cms.password_min')],
            'phone' => ['nullable', 'string', 'max:24'],
            'cnic' => ['nullable', 'string', 'max:20'],
            'campus_id' => ['nullable', Rule::exists('campuses', 'id')->where('organization_id', $orgId)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.role_id' => ['required', Rule::exists('roles', 'id')->where('organization_id', $orgId)],
            'roles.*.campus_id' => ['nullable', Rule::exists('campuses', 'id')->where('organization_id', $orgId)],
        ]);

        $user = User::create(collect($data)->only(['name', 'email', 'password', 'phone', 'cnic', 'campus_id'])
            ->merge(['organization_id' => $orgId])->all());
        $this->syncRoles($user, $data['roles']);
        return response()->json(['data' => $user->load('roleAssignments')], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:' . config('cms.password_min')],
            'phone' => ['nullable', 'string', 'max:24'],
            'cnic' => ['nullable', 'string', 'max:20'],
            'campus_id' => ['nullable'],
            'is_active' => ['boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*.role_id' => ['required', 'exists:roles,id'],
            'roles.*.campus_id' => ['nullable', 'exists:campuses,id'],
        ]);
        $passwordChanged = isset($data['password']) && $data['password'] !== '';
        if (isset($data['password']) && $data['password'] === '') unset($data['password']);
        unset($data['roles']);
        $user->update($data);
        if ($passwordChanged) $user->tokens()->delete();
        if ($request->has('roles')) $this->syncRoles($user, $request->input('roles'));
        return response()->json(['data' => $user->load('roleAssignments')]);
    }

    private function syncRoles(User $user, array $roles): void
    {
        $sync = [];
        foreach ($roles as $r) {
            $sync[$r['role_id']] = ['campus_id' => $r['campus_id'] ?? null];
        }
        $user->roleAssignments()->sync($sync);
        // Drop API tokens so permission changes take effect on next login.
        $user->tokens()->delete();
    }
}
