<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Accounts whose normal balance sits on the debit side. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }

    /** Signed balance in the account's normal direction, optionally including child accounts. */
    public function balance(bool $withChildren = false, ?string $from = null, ?string $to = null): float
    {
        $ids = collect([$this->id]);
        if ($withChildren) $ids = $ids->merge($this->children()->pluck('id'));

        $sums = JournalLine::query()
            ->whereIn('journal_lines.account_id', $ids)
            ->whereHas('voucher', function ($v) use ($from, $to) {
                $v->where('journal_vouchers.status', 'posted');
                if ($from) $v->whereDate('journal_vouchers.voucher_date', '>=', $from);
                if ($to) $v->whereDate('journal_vouchers.voucher_date', '<=', $to);
            })
            ->selectRaw('sum(debit) d, sum(credit) c')
            ->first();

        $net = (float) ($sums->d ?? 0) - (float) ($sums->c ?? 0);

        return $this->isDebitNormal() ? round($net, 2) : round(-$net, 2);
    }
}
