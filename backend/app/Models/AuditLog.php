<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLog extends Model
{
    public $updated_at = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(Model $subject, string $action, ?string $description = null, ?array $changes = null): void
    {
        $ctx = app(TenantContext::class);
        static::create([
            'organization_id' => $subject->organization_id ?? $ctx->organizationId ?: null,
            'campus_id' => $subject->campus_id ?? $ctx->campusId(),
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => get_class($subject),
            'auditable_id' => $subject->getKey(),
            'description' => $description ?? class_basename($subject) . ' ' . $action,
            'old_values' => $changes ? collect($changes)->map(fn ($c) => $c['old'] ?? null)->all() : null,
            'new_values' => $changes ? collect($changes)->map(fn ($c) => $c['new'] ?? null)->all() : $subject->only(['id']),
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 250),
            'created_at' => now(),
        ]);
    }
}
