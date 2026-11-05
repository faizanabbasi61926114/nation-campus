<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organization-only scoping for rows that must stay readable by both sides of a
 * conversation even when the two users sit on different campuses.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::creating(function ($model) {
            if (!$model->organization_id) $model->organization_id = app(TenantContext::class)->organizationId ?: null;
        });

        static::addGlobalScope('organization', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            if (!$ctx->organizationId) return; // console/seed context
            $table = $builder->getModel()->getTable();
            $builder->where($table . '.organization_id', $ctx->organizationId);
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }
}
