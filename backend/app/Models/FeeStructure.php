<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FeeStructure extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'amount' => 'decimal:2', 'gst_percent' => 'decimal:2'];
    }

    public function class()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
