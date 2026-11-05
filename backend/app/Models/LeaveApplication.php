<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeaveApplication extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected function casts(): array
    {
        return ['from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d', 'days' => 'decimal:2'];
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
