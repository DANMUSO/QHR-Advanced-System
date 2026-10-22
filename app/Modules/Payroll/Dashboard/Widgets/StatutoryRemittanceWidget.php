<?php

namespace App\Modules\Payroll\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;

/** Statutory remittances (PAYE, NSSF, SHIF, AHL, HELB) for the latest locked payroll month, due by the 9th of the next month. */
class StatutoryRemittanceWidget extends Widget
{
    public function key(): string   { return 'statutory_remittance'; }
    public function title(): string { return 'Statutory remittances'; }
    public function size(): string  { return 'sm'; }

    public function visible(User $user): bool
    {
        return $user->can('dashboard.finance');
    }

    public function data(User $user): array
    {
        $run = PayrollRun::whereIn('status', ['approved', 'paid'])
            ->orderByDesc('period')
            ->first(['id', 'title', 'period', 'status']);

        if (! $run) {
            return ['available' => false];
        }

        // Every locked run of the month (regular + off-cycle) is remitted together.
        $runIds = PayrollRun::whereIn('status', ['approved', 'paid'])->whereDate('period', $run->period->toDateString())->pluck('id');
        $lines = PayrollRunEmployee::whereIn('payroll_run_id', $runIds)->get(['paye', 'nssf_employee', 'nssf_employer', 'shif', 'ahl_employee', 'ahl_employer', 'helb']);

        // Kenya: PAYE, NSSF, SHIF, AHL all due by the 9th of the following month
        $dueDate = $run->period->copy()->addMonth()->day(9)->toDateString();
        $daysLeft = now()->startOfDay()->diffInDays($dueDate, false);
        $sum = fn (string ...$cols) => round((float) $lines->sum(fn ($l) => array_sum(array_map(fn ($c) => (float) $l->{$c}, $cols))), 2);

        $remittances = array_values(array_filter([
            ['name' => 'PAYE (KRA)',         'amount' => $sum('paye'),                          'due' => $dueDate],
            ['name' => 'NSSF',               'amount' => $sum('nssf_employee', 'nssf_employer'), 'due' => $dueDate],
            ['name' => 'SHIF',               'amount' => $sum('shif'),                          'due' => $dueDate],
            ['name' => 'Housing Levy (AHL)', 'amount' => $sum('ahl_employee', 'ahl_employer'),   'due' => $dueDate],
            ['name' => 'HELB',               'amount' => $sum('helb'),                          'due' => $dueDate],
        ], fn ($r) => $r['name'] !== 'HELB' || $r['amount'] > 0));

        return [
            'available'  => true,
            'run_title'  => $run->period->format('F Y').' payroll',
            'period'     => $run->period->format('F Y'),
            'due_date'   => $dueDate,
            'days_left'  => (int) $daysLeft,
            'overdue'    => $daysLeft < 0,
            'total'      => round(array_sum(array_column($remittances, 'amount')), 2),
            'remittances' => $remittances,
        ];
    }
}
