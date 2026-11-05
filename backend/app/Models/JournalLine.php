<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Posting lines carry no tenant columns of their own — their voucher does. */
class JournalLine extends Model
{
    use Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function voucher()
    {
        return $this->belongsTo(JournalVoucher::class, 'voucher_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
