<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Auditable;
use App\Support\BelongsToTenant;

class AcademicYear extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

}
