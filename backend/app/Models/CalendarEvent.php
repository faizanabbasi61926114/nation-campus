<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    /** Always serialized: the UI marks organisation-wide events differently from campus ones. */
    protected $appends = ['is_campus_wide'];

    public const TYPES = ['holiday', 'closure', 'exam', 'ptm', 'event', 'meeting', 'vacation'];
    public const AUDIENCES = ['all', 'students', 'faculty', 'staff'];

    /** Types that keep a campus shut, so attendance is never marked against them. */
    public const ATTENDANCE_TYPES = ['holiday', 'closure'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'affects_attendance' => 'boolean',
        ];
    }

    /** Null campus = every campus of the organization. */
    public function getIsCampusWideAttribute(): bool
    {
        return $this->campus_id === null;
    }

    public function scopeBetween($q, string $from, string $to)
    {
        return $q->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);
    }

    /** Ranges are inclusive, and Y-m-d strings compare correctly lexically. */
    public function covers(string $day): bool
    {
        return ($this->start_date?->toDateString() ?? '9999-12-31') <= $day
            && ($this->end_date?->toDateString() ?? '0000-01-01') >= $day;
    }

    public function affectsAttendanceForThisType(): bool
    {
        return in_array($this->type, self::ATTENDANCE_TYPES, true);
    }
}
