<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\HostelAllotment;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Hostel demo data: a boys block and a girls block per campus, rooms with real bed
 * capacities, and allotments that respect gender and capacity so the occupancy
 * figures on the page are believable.
 */
class HostelSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $isb = Campus::firstWhere('code', 'ISB');

        Setting::put(1, null, 'hostel.monthly_fee', 18000);
        Setting::put(1, null, 'hostel.include_mess', true);
        Setting::put(1, $lhr?->id, 'hostel.curfew', '21:30');

        $wardens = [
            'LHR' => User::firstWhere('email', 'principal.lhr@sunrise.edu.pk'),
            'ISB' => User::firstWhere('email', 'principal.isb@sunrise.edu.pk'),
        ];

        // [campus, block, gender, warden key, rooms: [number, floor, capacity, monthly fee, status]]
        $layout = [
            [$lhr, 'Jinnah House', 'boys', 'LHR', [
                ['H-1', 'Ground', 4, 22000, 'available'],
                ['H-2', 'Ground', 4, 22000, 'available'],
                ['H-3', 'First', 6, 18000, 'available'],
                ['H-4', 'First', 6, 18000, 'available'],
                ['H-5', 'Second', 3, 26000, 'available'],
                ['H-6', 'Second', 4, 20000, 'maintenance'],
            ]],
            [$lhr, 'Fatima House', 'girls', 'LHR', [
                ['G-1', 'Ground', 4, 22000, 'available'],
                ['G-2', 'Ground', 4, 22000, 'available'],
                ['G-3', 'First', 5, 19000, 'available'],
                ['G-4', 'First', 5, 19000, 'available'],
                ['G-5', 'Second', 2, 28000, 'available'],
            ]],
            [$isb, 'Iqbal House', 'boys', 'ISB', [
                ['B-1', 'Ground', 4, 21000, 'available'],
                ['B-2', 'Ground', 4, 21000, 'available'],
                ['B-3', 'First', 6, 17000, 'available'],
            ]],
            [$isb, 'Ruhana House', 'girls', 'ISB', [
                ['A-1', 'Ground', 4, 21000, 'available'],
                ['A-2', 'First', 3, 25000, 'available'],
            ]],
        ];

        $rooms = [];
        foreach ($layout as [$campus, $name, $gender, $wardenKey, $roomRows]) {
            if (!$campus) continue;
            $block = HostelBlock::firstOrCreate(
                ['organization_id' => 1, 'campus_id' => $campus->id, 'name' => $name],
                ['gender' => $gender, 'warden_user_id' => optional($wardens[$wardenKey])->id, 'is_active' => true],
            );
            foreach ($roomRows as [$number, $floor, $capacity, $fee, $status]) {
                $rooms[] = HostelRoom::firstOrCreate(
                    ['organization_id' => 1, 'hostel_block_id' => $block->id, 'number' => $number],
                    ['campus_id' => $campus->id, 'floor' => $floor, 'capacity' => $capacity,
                        'monthly_fee' => $fee, 'status' => $status],
                );
            }
        }

        $this->allotStudents($rooms);
    }

    /**
     * Fill the rooms bed by bed, matching each block's gender, then vacate a couple of
     * beds so the page shows both a free bed and a vacated allotment.
     */
    private function allotStudents(array $rooms): void
    {
        $approver = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk');
        $pools = [];      // "campus-gender" => students eligible for that house
        $cursors = [];
        $vacateEvery = 11;   // sprinkles a few vacated records through the history
        $n = 0;

        foreach ($rooms as $index => $room) {
            if (!$room->block || $room->status === 'maintenance') continue;

            $gender = $room->block->gender === 'boys' ? 'male' : 'female';
            $key = $room->campus_id . '-' . $gender;
            $pools[$key] ??= Student::where('campus_id', $room->campus_id)
                ->where('gender', $gender)->where('status', 'active')->orderBy('id')->get();
            $cursors[$key] ??= 0;
            $pool = $pools[$key];
            if ($pool->isEmpty()) continue;

            // Every third room runs full, the rest keep a spare bed for the demo allotment.
            $fill = max(1, (int) $room->capacity - ($index % 3 === 0 ? 0 : 1));
            for ($bed = 1; $bed <= $fill; $bed++) {
                $student = $pool->values()->get(($cursors[$key]++) % $pool->count());
                if (!$student) continue;
                // A student holds exactly one active bed, the same rule the controller enforces.
                if (HostelAllotment::where('student_id', $student->id)->where('status', 'active')->exists()) continue;

                $n++;
                $from = date('Y-m-d', strtotime('-' . (40 + ($n % 9) * 6) . ' days'));
                $vacated = $n % $vacateEvery === 0;

                HostelAllotment::firstOrCreate(
                    ['organization_id' => 1, 'student_id' => $student->id, 'status' => $vacated ? 'vacated' : 'active'],
                    [
                        'campus_id' => $student->campus_id,
                        'hostel_room_id' => $room->id,
                        'bed_no' => $bed,
                        'from_date' => $from,
                        'to_date' => $vacated ? date('Y-m-d', strtotime('-' . ($n % 7) . ' days')) : null,
                        'approved_by' => optional($room->block->warden)->id ?? optional($approver)->id,
                        'notes' => $vacated ? 'Family shifted cities; bed released.' : null,
                    ],
                );
            }
        }
    }
}
