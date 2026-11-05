<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTransport extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const KINDS = ['pickup', 'drop', 'both'];
    public const STATUSES = ['active', 'discontinued'];

    protected function casts(): array
    {
        return [
            'from_date' => 'date:Y-m-d',
            'to_date' => 'date:Y-m-d',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'transport_stop_id');
    }
}
