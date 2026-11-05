<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostelAllotment extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const STATUSES = ['active', 'vacated'];

    protected function casts(): array
    {
        return [
            'bed_no' => 'integer',
            'from_date' => 'date:Y-m-d',
            'to_date' => 'date:Y-m-d',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** "Ali Khan (26-014)" — the allotment table shows a person, never a bare id. */
    public function getStudentNameAttribute(): string
    {
        if (!$this->student) return '#' . $this->student_id;
        $name = trim("{$this->student->first_name} {$this->student->last_name}");
        return $this->student->roll_no ? "$name ({$this->student->roll_no})" : $name;
    }
}
