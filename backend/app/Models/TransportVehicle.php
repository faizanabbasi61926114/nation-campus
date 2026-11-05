<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportVehicle extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const TYPES = ['van', 'coaster', 'bus'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }
}
