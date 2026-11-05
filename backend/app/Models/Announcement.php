<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const AUDIENCES = ['all', 'staff', 'students', 'guardians'];
    public const PRIORITIES = ['normal', 'high', 'urgent'];
    public const STATUSES = ['draft', 'published', 'archived'];

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'date:Y-m-d',
            'send_email' => 'boolean',
            'send_sms' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class);
    }

    /** Published, not superseded by an expiry date, and visible on the given day. */
    public function scopeLive($q)
    {
        return $q->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()));
    }

    public function isScheduled(): bool
    {
        return $this->status === 'draft' && $this->publish_at && $this->publish_at->gt(now());
    }
}
