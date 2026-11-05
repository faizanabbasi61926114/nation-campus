<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExamMark extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['marks_obtained' => 'decimal:2', 'is_absent' => 'boolean'];
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function examSubject()
    {
        return $this->belongsTo(ExamSubject::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
