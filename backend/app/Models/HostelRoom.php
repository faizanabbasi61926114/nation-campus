<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelRoom extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const STATUSES = ['available', 'maintenance'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'monthly_fee' => 'decimal:2',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(HostelBlock::class, 'hostel_block_id');
    }

    public function allotments(): HasMany
    {
        return $this->hasMany(HostelAllotment::class);
    }

    /** Allotments still holding a bed, so the room grid can shade occupied beds. */
    public function liveAllotments(): HasMany
    {
        return $this->hasMany(HostelAllotment::class)->where('status', 'active');
    }
}
