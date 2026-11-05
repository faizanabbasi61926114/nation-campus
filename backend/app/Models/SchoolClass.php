<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Support\Auditable;
use App\Support\BelongsToTenant;

class SchoolClass extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected $table = 'classes';

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'class_id');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subjects', 'class_id', 'subject_id')->withPivot('is_compulsory');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

}
