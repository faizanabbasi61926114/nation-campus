<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FeePayment extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date:Y-m-d'];
    }

    public function invoice()
    {
        return $this->belongsTo(FeeInvoice::class, 'invoice_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
