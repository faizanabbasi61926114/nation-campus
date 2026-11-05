<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorLog extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const PHOTO_OPTIONS = ['yes', 'no'];

    protected function casts(): array
    {
        return [
            'in_at' => 'datetime:Y-m-d H:i:s',
            'out_at' => 'datetime:Y-m-d H:i:s',
        ];
    }

    public function meetsUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'meets_user_id');
    }

    public function meetsStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'meets_student_id');
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('out_at');
    }
}
