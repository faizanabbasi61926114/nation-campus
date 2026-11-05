<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One-on-one message between two users of the same organization. Threads are
 * organization-wide on purpose: sender and recipient may sit on different
 * campuses, so this model deliberately uses the organization-only scope.
 *
 * Deliberately NOT audited: audit_logs would copy private message bodies into
 * the change payload readable by audit.view holders. Delivery attempts are
 * tracked in communication_logs instead.
 */
class Message extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'send_email' => 'boolean', 'send_sms' => 'boolean'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id');
    }

    /** Every row of a thread, oldest first. */
    public function threadRows(): \Illuminate\Support\Collection
    {
        $root = $this->parent_id ?: $this->id;
        return static::where('id', $root)->orWhere('parent_id', $root)->orderBy('created_at')->get();
    }

    public function scopeInvolving(Builder $q, int $userId): Builder
    {
        return $q->where(fn ($w) => $w->where('sender_id', $userId)->orWhere('recipient_id', $userId));
    }

    public function scopeUnread(Builder $q, int $userId): Builder
    {
        return $q->where('recipient_id', $userId)->whereNull('read_at');
    }

    public function isFrom(int $userId): bool
    {
        return (int) $this->sender_id === $userId;
    }
}
