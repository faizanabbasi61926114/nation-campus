<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryLoan extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const STATUSES = ['borrowed', 'returned', 'lost'];

    protected function casts(): array
    {
        return [
            'copy_no' => 'integer',
            'issued_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'returned_on' => 'date:Y-m-d',
            'fine_amount' => 'decimal:2',
            'fine_paid' => 'boolean',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class, 'library_book_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', LibraryBook::OPEN_STATUSES);
    }

    /** Out past its due date — the reader still owes the book, not only the fine. */
    public function scopeOverdue(Builder $q): Builder
    {
        return $q->open()->whereDate('due_on', '<', now()->toDateString());
    }

    /** The human the copy went to: a student name, a staff name, or an id as a last resort. */
    public function getBorrowerNameAttribute(): string
    {
        if ($this->student) {
            return trim("{$this->student->first_name} {$this->student->last_name}");
        }
        if ($this->staff) {
            return trim("{$this->staff->first_name} {$this->staff->last_name}");
        }
        return '#' . ($this->student_id ?: $this->staff_id);
    }

    /** Days past the due date — measured to the return date, or to today while the copy is out. */
    public function daysLate(?string $on = null): int
    {
        if (!$this->due_on) return 0;
        $reference = Carbon::parse($on ?? ($this->returned_on?->toDateString() ?? now()->toDateString()))->startOfDay();
        $due = $this->due_on->copy()->startOfDay();
        return $reference->greaterThan($due) ? (int) $due->diffInDays($reference) : 0;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->daysLate() > 0;
    }
}
