<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use App\Support\BelongsToTenant;
use App\Support\TenantContext;

/**
 * Implicit route-model binding resolves before ResolveCampus has populated the
 * tenant context, so a guessed id could reach another campus. Re-check every bound
 * record once the context is known.
 */
class EnforceTenantOnBindings
{
    public function handle(Request $request, Closure $next)
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->organizationId) return $next($request);

        foreach ($request->route()?->parameters() ?? [] as $binding) {
            if (!$binding instanceof Model) continue;
            $attributes = $binding->getAttributes();

            if (array_key_exists('organization_id', $attributes)
                && (int) ($attributes['organization_id'] ?? 0) !== $ctx->organizationId) {
                abort(404);
            }

            // The campus rule mirrors the model's own scoping contract: BelongsToTenant rows
            // are campus-partitioned, BelongsToOrganization rows (message threads, delivery
            // logs) deliberately are not, so a thread stays readable on both sides.
            $campusScoped = in_array(BelongsToTenant::class, class_uses_recursive($binding), true);
            if ($ctx->campusId() && $campusScoped && array_key_exists('campus_id', $attributes)
                && $attributes['campus_id'] !== null
                && (int) $attributes['campus_id'] !== $ctx->campusId()) {
                abort(404);
            }
        }

        return $next($request);
    }
}
