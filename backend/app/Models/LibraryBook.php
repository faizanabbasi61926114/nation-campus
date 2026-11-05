<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryBook extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const CATEGORIES = ['Textbook', 'Reference', 'Story', 'Magazine'];

    /** A copy is still out until it comes back — a lost copy counts against the holding too. */
    public const OPEN_STATUSES = ['borrowed', 'lost'];

    protected function casts(): array
    {
        return [
            'total_copies' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class);
    }

    /** Loans still holding a copy of this title. */
    public function openLoans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class)->whereIn('status', self::OPEN_STATUSES);
    }

    /** Only titles a librarian may issue: active rows, this campus or organization-shared. */
    public function scopeIssueable(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
