<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Delivery attempt for one recipient over one channel — the outbound audit trail. */
class CommunicationLog extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    public const CHANNELS = ['email', 'sms', 'in_app'];
    public const STATUSES = ['sent', 'skipped', 'failed'];

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
