<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Designation extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
