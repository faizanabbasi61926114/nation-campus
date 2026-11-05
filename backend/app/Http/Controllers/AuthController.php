<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('roleAssignments.permissions')->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (!$user->is_active) {
            return response()->json(['message' => 'Account is disabled. Contact your administrator.'], 403);
        }

        $token = $user->createToken('cms-' . now()->timestamp, $user->permissionsList())->plainTextToken;
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditLog::create([
            'organization_id' => $user->organization_id,
            'campus_id' => $user->campus_id,
            'user_id' => $user->id,
            'action' => 'login',
            'description' => 'User signed in',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 250),
            'created_at' => now(),
        ]);

        return response()->json(['token' => $token, 'user' => $this->profile($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()->load('roleAssignments')));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Signed out']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:' . config('cms.password_min'), 'confirmed'],
        ]);
        $user = $request->user();
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }
        $user->update(['password' => $data['password']]);
        return response()->json(['message' => 'Password updated']);
    }

    private function profile(User $user): array
    {
        $campuses = $user->organization?->campuses ?? collect();
        $accessible = $user->accessibleCampusIds();
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roleSlugs(),
            'permissions' => $user->permissionsList(),
            'organization_id' => $user->organization_id,
            'campus_id' => $user->campus_id,
            'is_head_office' => $user->isHeadOffice(),
            'campuses' => $campuses->whereIn('id', $accessible)
                ->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'code' => $c->code,
                    'city' => $c->city, 'school_type' => $c->school_type,
                ])->values(),
        ];
    }
}
