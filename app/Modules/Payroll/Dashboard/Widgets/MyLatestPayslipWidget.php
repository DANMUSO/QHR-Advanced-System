<?php

namespace App\Modules\Payroll\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollRunEmployee;

/** The signed-in employee's latest payslip net pay (masked until clicked). */
class MyLatestPayslipWidget extends Widget
{
    public function key(): string   { return 'my_latest_payslip'; }
    public function title(): string { return 'Latest payslip'; }
    public function size(): string  { return 'sm'; }

    public function visible(User $user): bool
    {
        return $user->employee !== null && $user->can('payroll.view-own');
    }

    public function data(User $user): array
    {
        $line = PayrollRunEmployee::withoutGlobalScopes()
            ->where('employee_id', $user->employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->with('run:id,title,period,pay_date,status')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_employees.payroll_run_id')
            ->orderByDesc('payroll_runs.period')
            ->select('payroll_run_employees.*')
            ->first();

        if (! $line) {
            return ['available' => false];
        }

        return [
            'available'        => true,
            'period_label'     => $line->run?->period?->format('F Y'),
            'pay_date'         => $line->run?->pay_date?->toDateString(),
            'gross_pay'        => (float) $line->gross_pay,
            'total_deductions' => (float) $line->total_deductions,
            'net_pay'          => (float) $line->net_pay,
            'paye'             => (float) $line->paye,
            'payslip_id'       => $line->id,
        ];
    }
}
