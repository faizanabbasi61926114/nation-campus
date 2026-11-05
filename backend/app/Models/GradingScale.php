<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GradingScale extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];
}
