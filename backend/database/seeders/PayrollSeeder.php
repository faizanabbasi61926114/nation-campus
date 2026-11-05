<?php

namespace Database\Seeders;

use App\Http\Controllers\PayrollController;
use App\Models\Campus;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;

/**
 * Drives the real payroll controller so seeded runs are calculated with the same
 * EOBI / income-tax / unpaid-leave rules the API applies.
 */
class PayrollSeeder extends Seeder
{
    public function run(): void
    {
        $finance = User::firstWhere('email', 'finance@sunrise.edu.pk');
        $controller = app(PayrollController::class);

        $runs = [
            ['LHR', '2026-09', 'paid'],
            ['ISB', '2026-09', 'paid'],
            ['LHR', '2026-10', 'calculated'],
            ['ISB', '2026-10', 'calculated'],
        ];

        foreach ($runs as [$code, $month, $target]) {
            $campus = Campus::firstWhere('code', $code);
            $run = PayrollRun::firstOrCreate(
                ['campus_id' => $campus->id, 'month' => $month],
                [
                    'organization_id' => 1, 'name' => 'Monthly payroll — ' . $month,
                    'status' => 'draft', 'prepared_by' => $finance->id,
                ],
            );

            $this->calculate($controller, $finance, $run);
            if ($target === 'paid') {
                $this->transition($controller, $finance, $run, 'approved');
                $this->transition($controller, $finance, $run, 'paid');
            }
        }
    }

    private function calculate(PayrollController $controller, User $as, PayrollRun $run): void
    {
        if ($run->fresh()->payslips()->count() > 0) return;
        $controller->calculate($this->request($as), $run);
    }

    private function transition(PayrollController $controller, User $as, PayrollRun $run, string $status): void
    {
        $request = $this->request($as);
        $request->replace(['status' => $status]);
        $controller->transition($request, $run->fresh());
    }

    private function request(User $as): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $as);
        return $request;
    }
}
