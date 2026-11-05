<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const STATUSES = ['present', 'absent', 'late', 'leave', 'excused'];

    protected function casts(): array
    {
        // Y-m-d serialization keeps the stored value comparable with the
        // plain date strings used in unique-key lookups on every driver.
        return ['date' => 'date:Y-m-d'];
    }
}
