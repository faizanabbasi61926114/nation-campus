<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportStop extends Model
{
    use Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_no' => 'integer'];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }
}
