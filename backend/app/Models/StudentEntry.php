<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gate tap for one student. Deliberately append-only: the day's story is the
 * sequence of rows, so corrections are new taps rather than edits of an old one.
 */
class StudentEntry extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const DIRECTIONS = ['in', 'out'];
    public const SOURCES = ['front_desk', 'roll_call'];

    protected function casts(): array
    {
        return [
            'at' => 'datetime:Y-m-d H:i:s',
            'parent_notified_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isArrival(): bool
    {
        return $this->direction === 'in';
    }

    /** The verb a sentence about this tap needs. */
    public static function verb(string $direction): string
    {
        return $direction === 'in' ? 'entered' : 'left';
    }

    public function scopeOnDate(Builder $q, string $date): Builder
    {
        return $q->whereDate('at', $date);
    }

    /** True when the family has already been alerted about this direction today. */
    public static function alreadyNotifiedToday(int $studentId, string $direction, string $date): bool
    {
        return static::withoutGlobalScopes()
            ->where('student_id', $studentId)
            ->where('direction', $direction)
            ->whereDate('at', $date)
            ->whereNotNull('parent_notified_at')
            ->exists();
    }
}
