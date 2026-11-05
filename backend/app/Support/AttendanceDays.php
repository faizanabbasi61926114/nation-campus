<?php

namespace App\Support;

/**
 * Attendance is recorded on the day register and, since the phone roll call, per class
 * period — so one student can hold six rows for a single school day. Every percentage a
 * family or a principal reads counts school days, never rows: the register decides the
 * day, and where no register was marked the day is present if any period was.
 */
class AttendanceDays
{
    /** Aggregated over rows of one (subject_id, date): the status that day resolved to. */
    public static function dayStatus(): string
    {
        $onRegister = fn (string $status) => "max(case when period_no = 0 and status = '{$status}' then 1 else 0 end) = 1";
        $inDay = fn (string $in) => "max(case when status in ({$in}) then 1 else 0 end) = 1";

        return "case
            when {$onRegister('present')} then 'present'
            when {$onRegister('late')} then 'late'
            when {$onRegister('absent')} then 'absent'
            when {$onRegister('leave')} then 'leave'
            when {$onRegister('excused')} then 'excused'
            when {$inDay("'present','late'")} then 'present'
            when {$inDay("'absent'")} then 'absent'
            when {$inDay("'leave'")} then 'leave'
            else 'excused'
        end";
    }

    /** Over a set of day rows: the same shape the monthly sheet and the family app expect. */
    public static function rollup(): string
    {
        return "sum(case when day_status in ('present','late') then 1 else 0 end) as present,
            sum(case when day_status = 'absent' then 1 else 0 end) as absent,
            sum(case when day_status = 'leave' then 1 else 0 end) as leave_days,
            sum(case when day_status = 'excused' then 1 else 0 end) as excused,
            sum(case when day_status = 'late' then 1 else 0 end) as late,
            count(*) as total";
    }
}
