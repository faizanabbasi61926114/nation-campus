<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function staff()
    {
        return $this->hasMany(Staff::class);
    }
}
