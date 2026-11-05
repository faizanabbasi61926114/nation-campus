<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Auditable;

class Campus extends Model
{
    use Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean', 'is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Setting::getFor($this->organization_id, $this->id, $key, $default);
    }
}
