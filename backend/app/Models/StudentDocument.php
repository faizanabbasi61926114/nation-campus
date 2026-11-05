<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Auditable;
use App\Support\BelongsToTenant;

class StudentDocument extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

}
