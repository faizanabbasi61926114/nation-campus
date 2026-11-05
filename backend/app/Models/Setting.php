<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function getFor(int $organizationId, ?int $campusId, string $key, mixed $default = null): mixed
    {
        if ($campusId) {
            $row = static::where(['organization_id' => $organizationId, 'campus_id' => $campusId, 'key' => $key])->first();
            if ($row) return $row->value;
        }
        $row = static::where(['organization_id' => $organizationId, 'campus_id' => null, 'key' => $key])->first();
        return $row ? $row->value : $default;
    }

    public static function put(int $organizationId, ?int $campusId, string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['organization_id' => $organizationId, 'campus_id' => $campusId, 'key' => $key],
            ['value' => $value],
        );
    }
}
