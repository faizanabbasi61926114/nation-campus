<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FeeInvoice extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d'];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function structure()
    {
        return $this->belongsTo(FeeStructure::class, 'fee_structure_id');
    }

    public function payments()
    {
        return $this->hasMany(FeePayment::class, 'invoice_id');
    }

    /** Net payable after GST/discount/late-fee, minus cleared payments. */
    public function totalDue(): float
    {
        return max(0, (float) $this->amount + (float) $this->gst_amount + (float) $this->late_fee - (float) $this->discount);
    }

    public function paidAmount(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->where('status', 'cleared')->sum('amount');
        }
        return (float) $this->payments()->where('status', 'cleared')->sum('amount');
    }

    public function getBalanceAttribute(): float
    {
        return round($this->totalDue() - $this->paidAmount(), 2);
    }
}
