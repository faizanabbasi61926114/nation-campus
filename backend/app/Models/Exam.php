<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const STATUSES = ['draft', 'scheduled', 'ongoing', 'completed', 'published', 'archived'];
    public const TRANSITIONS = [
        'draft' => ['scheduled', 'archived'],
        'scheduled' => ['ongoing', 'draft', 'archived'],
        'ongoing' => ['completed', 'archived'],
        'completed' => ['published', 'ongoing'],
        'published' => ['archived'],
        'archived' => [],
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function subjects()
    {
        return $this->hasMany(ExamSubject::class);
    }

    public function marks()
    {
        return $this->hasMany(ExamMark::class);
    }
}
