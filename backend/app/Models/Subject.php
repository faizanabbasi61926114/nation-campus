<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Support\Auditable;
use App\Support\BelongsToTenant;

class Subject extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_subjects', 'subject_id', 'class_id');
    }

}
