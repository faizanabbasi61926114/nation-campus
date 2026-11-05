<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class HostelBlock extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const GENDERS = ['boys', 'girls'];

    /** A boys block houses male students, a girls block female students. */
    public const GENDER_MAP = ['boys' => 'male', 'girls' => 'female'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class);
    }

    public function allotments(): HasManyThrough
    {
        return $this->hasManyThrough(HostelAllotment::class, HostelRoom::class);
    }

    public function warden(): BelongsTo
    {
        return $this->belongsTo(User::class, 'warden_user_id');
    }

    /** Student genders this block may admit. */
    public function acceptsGender(string $gender): bool
    {
        return (self::GENDER_MAP[$this->gender] ?? $this->gender) === $gender;
    }
}
