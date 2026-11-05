<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const STATUSES = ['draft', 'calculated', 'approved', 'paid'];
    public const TRANSITIONS = [
        'draft' => ['calculated', 'draft'],
        'calculated' => ['approved', 'draft'],
        'approved' => ['paid'],
        'paid' => [],
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date:Y-m-d',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
        ];
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
