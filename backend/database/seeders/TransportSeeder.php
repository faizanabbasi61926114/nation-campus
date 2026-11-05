<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use Illuminate\Database\Seeder;

/**
 * Demo transport network for the Lahore and Islamabad campuses: a few vehicles,
 * routes with ordered stops, and a small set of active student assignments.
 */
class TransportSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $isb = Campus::firstWhere('code', 'ISB');

        $this->seedCampus($lhr, [
            ['VAN-01', 'BUS-LHR-1234', 'van', 20, 'Shafqat Ali', '0300-1234501'],
            ['COASTER-02', 'BUS-LHR-5566', 'coaster', 30, 'Nadeem Iqbal', '0301-1234502'],
            ['BUS-03', 'BUS-LHR-7788', 'bus', 45, 'Rashid Mehmood', '0302-1234503'],
        ], [
            ['R-01', 'Gulberg — Johar Town', 'VAN-01', '06:45', '14:30', 6500, ['Gulberg III', 'Faisal Canal', 'Johar Town'], 4],
            ['R-02', 'Cantt — Model Town', 'COASTER-02', '06:50', '14:35', 7000, ['Cantt Rail Station', 'M.M. Alam Road', 'Model Town Link'], 5],
            ['R-03', 'Bahria Town', 'BUS-03', '06:30', '14:20', 8000, ['Bahria Gates', 'Rawalpindi Road', 'Canal Bank'], 6],
        ]);

        $this->seedCampus($isb, [
            ['VAN-01', 'BUS-ISB-4455', 'van', 18, 'Zubair Khan', '0333-1234511'],
            ['COASTER-02', 'BUS-ISB-9900', 'coaster', 28, 'Adil Sheikh', '0334-1234512'],
        ], [
            ['R-01', 'G-11 — F-series', 'VAN-01', '07:00', '14:45', 6000, ['G-11 Markaz', 'F-8 Markaz', 'F-6 Boulevard'], 3],
            ['R-02', 'Bahria Enclave — ICP', 'COASTER-02', '06:55', '14:40', 7200, ['Bahria Enclave', 'Park Road', 'ICP CHB'], 4],
        ]);
    }

    private function seedCampus(Campus $campus, array $vehicles, array $routes): void
    {
        $vehicleByCode = [];
        foreach ($vehicles as [$code, $plate, $type, $capacity, $driver, $phone]) {
            $vehicleByCode[$code] = TransportVehicle::firstOrCreate(
                ['campus_id' => $campus->id, 'code' => $code],
                [
                    'organization_id' => $campus->organization_id, 'plate_no' => $plate, 'type' => $type,
                    'capacity' => $capacity, 'driver_name' => $driver, 'driver_phone' => $phone, 'is_active' => true,
                ],
            );
        }

        $students = Student::where('campus_id', $campus->id)->where('status', 'active')->orderBy('id')->get();
        $cursor = 0;

        foreach ($routes as $i => [$code, $name, $vehicleCode, $pickup, $drop, $fee, $stops, $assignCount]) {
            $route = TransportRoute::firstOrCreate(
                ['campus_id' => $campus->id, 'code' => $code],
                [
                    'organization_id' => $campus->organization_id, 'name' => $name,
                    'transport_vehicle_id' => $vehicleByCode[$vehicleCode]->id,
                    'pickup_time' => $pickup, 'drop_time' => $drop, 'monthly_fee' => $fee, 'is_active' => true,
                    'notes' => "Morning pickup {$pickup}, evening drop {$drop}.",
                ],
            );

            $stopModels = [];
            foreach ($stops as $order => $stopName) {
                $stopModels[] = TransportStop::firstOrCreate(
                    ['transport_route_id' => $route->id, 'order_no' => $order + 1],
                    ['name' => $stopName, 'pickup_time' => $pickup, 'drop_time' => $drop],
                );
            }

            for ($n = 0; $n < $assignCount && $cursor < $students->count(); $n++) {
                $student = $students[$cursor++];
                $stop = $stopModels[$n % count($stopModels)];
                StudentTransport::firstOrCreate(
                    ['student_id' => $student->id, 'transport_route_id' => $route->id],
                    [
                        'organization_id' => $campus->organization_id, 'campus_id' => $campus->id,
                        'transport_stop_id' => $stop->id, 'kind' => 'both',
                        'from_date' => '2026-04-01', 'status' => 'active',
                    ],
                );
            }
        }
    }
}
