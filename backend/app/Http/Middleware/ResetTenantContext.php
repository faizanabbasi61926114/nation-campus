<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Route-model binding resolves before auth and ResolveCampus run, so it reads whatever
 * the context holds at that moment. Under a persistent runtime the singleton survives
 * the previous request, and a campus chosen earlier would silently hide — or expose —
 * rows in this one. Start every request from the boot-time context; ResolveCampus owns
 * the rest, and EnforceTenantOnBindings re-checks the rows that were already resolved.
 */
class ResetTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $ctx = app(TenantContext::class);
        $ctx->user = null;
        $ctx->campus = null;
        $ctx->organizationId = (int) (config('cms.default_organization_id') ?: 0);

        return $next($request);
    }
}
