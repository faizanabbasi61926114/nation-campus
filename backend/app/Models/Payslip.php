<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'basic' => 'decimal:2', 'house_rent' => 'decimal:2', 'medical' => 'decimal:2',
            'other_earnings' => 'decimal:2', 'gross' => 'decimal:2', 'eobi' => 'decimal:2',
            'income_tax' => 'decimal:2', 'other_deductions' => 'decimal:2',
            'total_deductions' => 'decimal:2', 'net_pay' => 'decimal:2',
        ];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
