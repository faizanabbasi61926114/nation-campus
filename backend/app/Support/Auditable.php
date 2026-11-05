<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::updated(function (Model $m) {
            $changes = collect($m->getDirty())
                ->except(['updated_at', 'password', 'remember_token'])
                ->map(fn ($v, $k) => ['new' => $v, 'old' => $m->getOriginal($k)])
                ->all();
            if ($changes) {
                AuditLog::record($m, 'updated', null, $changes);
            }
        });
        static::created(fn (Model $m) => AuditLog::record($m, 'created'));
        static::deleted(fn (Model $m) => AuditLog::record($m, 'deleted'));
    }
}
