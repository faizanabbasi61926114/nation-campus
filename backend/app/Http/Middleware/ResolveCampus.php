<?php

namespace App\Http\Middleware;

use App\Models\Campus;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveCampus
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        $this->context->user = $user;
        $this->context->organizationId = (int) $user->organization_id;
        $this->context->campus = null;

        $requested = $request->header('X-Campus-Id') ?: $request->input('campus_id');
        $allowed = $user->accessibleCampusIds();

        if ($requested) {
            if (!in_array((int) $requested, $allowed, true)) {
                return response()->json(['message' => 'Campus not accessible for this account.'], 403);
            }
            $this->context->campus = Campus::find((int) $requested);
        } elseif (count($allowed) === 1) {
            $this->context->campus = Campus::find($allowed[0]);
        } elseif (!$user->isHeadOffice() && $user->campus_id && in_array($user->campus_id, $allowed, true)) {
            $this->context->campus = Campus::find($user->campus_id);
        }

        return $next($request);
    }
}
