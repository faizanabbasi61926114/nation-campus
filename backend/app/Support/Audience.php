<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Campus;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns an announcement audience into real, reachable accounts.
 *
 * Students have no login, so the `students` audience is delivered to the guardian
 * accounts linked on the student record — the same seats as `guardians`, but the
 * student head-count is reported separately for reach.
 */
class Audience
{
    /**
     * @return array{user_ids: int[], students: int, staff: int, guardian_phones: string[]}
     */
    public static function reach(int $organizationId, ?int $campusId, string $audience): array
    {
        $campusIds = $campusId
            ? [$campusId]
            : Campus::where('organization_id', $organizationId)->pluck('id')->all();

        $students = Student::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('campus_id', $campusIds)
            ->where('status', 'active')
            ->get(['id', 'guardian_user_id', 'guardian_phone']);

        $guardianIds = $students->pluck('guardian_user_id')->filter()->unique()->values()->all();

        $campusUsers = User::query()
            ->where('organization_id', $organizationId)->where('is_active', true)
            ->whereIn('campus_id', $campusIds)
            ->pluck('id')->all();

        $staffLinked = Staff::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('campus_id', $campusIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')->unique()->values()->all();

        $staff = array_values(array_unique(array_merge(
            array_diff($campusUsers, $guardianIds),
            $staffLinked,
        )));

        $userIds = match ($audience) {
            'staff' => $staff,
            'guardians', 'students' => $guardianIds,
            default => array_values(array_unique(array_merge($staff, $guardianIds))),
        };

        // Guardians of enrolled students are reachable by SMS even without a login.
        $households = in_array($audience, ['students', 'guardians', 'all'], true);

        return [
            'user_ids' => $userIds,
            'students' => $students->count(),
            'staff' => count($staff),
            'guardian_phones' => $households
                ? $students->pluck('guardian_phone')->filter()->unique()->values()->all()
                : [],
        ];
    }

    /** @return Collection<int, User> active users only, ordered by name */
    public static function usersFor(Announcement $announcement): Collection
    {
        $ids = static::reach($announcement->organization_id, $announcement->campus_id, $announcement->audience)['user_ids'];
        if (!$ids) return collect();
        return User::where('is_active', true)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'email', 'phone', 'campus_id']);
    }

    /** Counts shown in the composer before anything is sent. */
    public static function summary(int $organizationId, ?int $campusId, string $audience): array
    {
        $r = static::reach($organizationId, $campusId, $audience);
        $users = User::where('is_active', true)->whereIn('id', $r['user_ids'])->get(['id', 'email', 'phone']);
        $sms = app(Sms::class);

        $phones = collect($r['guardian_phones'])
            ->concat($users->pluck('phone'))
            ->map(fn ($p) => $sms->normalize($p))
            ->filter()
            ->unique();

        return [
            'audience' => $audience,
            'seats' => $users->count(),
            'with_email' => $users->filter(fn (User $u) => filled($u->email))->count(),
            'mobile_numbers' => $phones->count(),
            'students' => $r['students'],
            'staff' => $r['staff'],
        ];
    }
}
