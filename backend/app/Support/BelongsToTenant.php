<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to all tenant domain models. Auto-assigns organization/campus on
 * create and filters queries by the active TenantContext.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            $ctx = app(TenantContext::class);
            if (!$model->organization_id) $model->organization_id = $ctx->organizationId ?: null;
            if (!$model->campus_id && $ctx->campusId()) $model->campus_id = $ctx->campusId();
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $ctx = app(TenantContext::class);
            if (!$ctx->organizationId) return; // console/seed context
            $table = $builder->getModel()->getTable();
            $builder->where($table . '.organization_id', $ctx->organizationId);
            if ($ctx->campusId()) {
                $campusId = $ctx->campusId();
                $builder->where(fn (Builder $q) => $q->where($table . '.campus_id', $campusId)
                    ->orWhereNull($table . '.campus_id'));
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Campus::class);
    }

    /** Abort 404 when a resolved record falls outside the active campus scope. */
    public function scopeWhereInActiveCampus(Builder $q): Builder
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return $q;
        return $q->where(fn (Builder $w) => $w->where($this->getTable() . '.campus_id', $ctx->campusId())->orWhereNull($this->getTable() . '.campus_id'));
    }
}
