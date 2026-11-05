<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplineCase extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const CATEGORIES = ['uniform', 'punctuality', 'bullying', 'conduct', 'damage', 'attendance', 'other'];
    public const SEVERITIES = ['minor', 'moderate', 'major'];
    public const ACTIONS = ['none', 'counselled', 'verbal_warning', 'parent_called', 'detention', 'suspension'];
    public const STATUSES = ['open', 'closed'];

    /** Closing a case must record what was actually done, so "none" is not a closing action. */
    public const CLOSING_ACTIONS = ['counselled', 'verbal_warning', 'parent_called', 'detention', 'suspension'];

    protected function casts(): array
    {
        return [
            'incident_on' => 'date:Y-m-d',
            'suspension_days' => 'integer',
            'parent_notified' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function studentName(): string
    {
        return trim(($this->student?->first_name ?? '') . ' ' . ($this->student?->last_name ?? '')) ?: 'the student';
    }
}
