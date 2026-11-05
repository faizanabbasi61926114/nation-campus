<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class JournalVoucher extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const TYPES = ['receipt', 'payment', 'journal', 'contra'];

    protected function casts(): array
    {
        return ['voucher_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'voucher_id');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function debits(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function credits(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }
}
