<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = Role::with('permissions')->where('organization_id', $request->user()->organization_id)->get();
        return response()->json(['data' => $roles]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::orderBy('group')->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', Rule::unique('roles')->where('organization_id', $request->user()->organization_id)],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);
        $role = Role::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);
        $role->permissions()->sync($data['permissions']);
        return response()->json(['data' => $role->load('permissions')], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->organization_id === $request->user()->organization_id, 404);
        abort_if($role->is_system && $request->has('slug'), 422, 'System role slug is immutable.');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);
        $role->update(collect($data)->except('permissions')->all());
        if ($request->has('permissions')) {
            $role->permissions()->sync($data['permissions']);
            $role->users()->each(fn ($u) => $u->tokens()->delete());
        }
        return response()->json(['data' => $role->load('permissions')]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->organization_id === $request->user()->organization_id, 404);
        abort_if($role->is_system, 422, 'System roles cannot be deleted.');
        $role->delete();
        return response()->json(['message' => 'Role deleted']);
    }
}
