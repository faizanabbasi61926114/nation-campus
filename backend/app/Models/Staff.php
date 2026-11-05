<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    /** Never expose the national ID over the API, even though staff.view can read it. */
    protected $hidden = ['cnic'];

    public const LEAVE_TYPES = ['annual', 'casual', 'sick', 'earned', 'unpaid', 'media'];
    public const STATUSES = ['active', 'probation', 'resigned', 'terminated'];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date:Y-m-d',
            'confirmed_on' => 'date:Y-m-d',
            'resigned_on' => 'date:Y-m-d',
            'cnic' => 'encrypted',
            'base_salary' => 'decimal:2',
            'house_rent_percent' => 'decimal:2',
            'medical_allowance' => 'decimal:2',
            'other_deductions' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    public function leaves()
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Monthly cost before tax: basic + house rent + medical. */
    public function grossSalary(): float
    {
        $basic = (float) $this->base_salary;
        return round($basic + $basic * (float) $this->house_rent_percent / 100 + (float) $this->medical_allowance, 2);
    }
}
