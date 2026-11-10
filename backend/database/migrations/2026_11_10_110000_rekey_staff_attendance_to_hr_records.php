<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The staff register used to be keyed on the login, which meant the guard and the
 * cleaner had no row to mark — neither signs in. Attendance for staff is keyed on the
 * HR record from here on, so the roster is the campus staff list.
 *
 * Rows for a login that has no HR record are dropped rather than guessed at; re-seeding
 * AttendanceSeeder rebuilds them against the staff records.
 */
return new class extends Migration
{
    public function up(): void
    {
        $staffForUser = DB::table('staff')->whereNotNull('user_id')->pluck('id', 'user_id');

        // One row at a time by primary key: a login id can numerically equal a staff id,
        // so a blanket update would re-key rows this migration already moved.
        foreach (DB::table('attendance_records')->where('subject_type', 'staff')->get(['id', 'subject_id']) as $row) {
            $staffId = $staffForUser[$row->subject_id] ?? null;
            if ($staffId) {
                DB::table('attendance_records')->where('id', $row->id)->update(['subject_id' => $staffId]);
            } else {
                DB::table('attendance_records')->where('id', $row->id)->delete();
            }
        }
    }

    /** Re-keying is one-way by design: only the seeder can rebuild a staff register. */
    public function down(): void
    {
        //
    }
};
