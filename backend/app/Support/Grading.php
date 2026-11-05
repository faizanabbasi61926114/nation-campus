<?php

namespace App\Support;

use App\Models\GradingScale;

/**
 * Grade bands as a school publishes them, so the grade a parent reads in the
 * mobile app is the one the exam controller's report card computed.
 */
class Grading
{
    private const FALLBACK = [[90, 'A+'], [80, 'A'], [70, 'B'], [60, 'C'], [50, 'D'], [40, 'E'], [0, 'F']];

    public static function percent(float $obtained, int|float $max): float
    {
        return $max > 0 ? round($obtained / $max * 100, 2) : 0.0;
    }

    public static function label(int $orgId, ?int $campusId, float $obtained, int|float $max): string
    {
        $pct = self::percent($obtained, $max);
        $band = self::band($orgId, $campusId, $pct);
        if ($band) return $band['grade'];
        foreach (self::FALLBACK as [$min, $grade]) {
            if ($pct >= $min) return $grade;
        }
        return 'F';
    }

    public static function remark(int $orgId, ?int $campusId, float $obtained, int|float $max): ?string
    {
        return self::band($orgId, $campusId, self::percent($obtained, $max))?->remark;
    }

    /** The campus scale wins over the organization default. */
    private static function band(int $orgId, ?int $campusId, float $percent): ?GradingScale
    {
        $rows = GradingScale::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where(fn ($q) => $q->whereNull('campus_id')->orWhere('campus_id', $campusId))
            ->where('min_percent', '<=', $percent)
            ->orderByDesc('min_percent')
            ->get();
        $campusRow = $campusId ? $rows->firstWhere('campus_id', $campusId) : null;
        return $campusRow ?? $rows->first();
    }
}
