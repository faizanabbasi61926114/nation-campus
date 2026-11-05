<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        foreach ($permissions as $perm) {
            if ($user && $user->hasPermission($perm)) return $next($request);
        }
        return response()->json(['message' => 'Insufficient permissions.'], 403);
    }
}
