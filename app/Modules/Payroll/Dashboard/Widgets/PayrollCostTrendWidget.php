<?php

namespace App\Modules\Payroll\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollRun;

/** Gross, net and employer cost of approved / paid payroll (all run types) for the last 12 months. */
class PayrollCostTrendWidget extends Widget
{
    public function key(): string   { return 'payroll_cost_trend'; }
    public function title(): string { return 'Payroll cost trend (12 months)'; }
    public function size(): string  { return 'lg'; }

    public function visible(User $user): bool
    {
        return $user->can('dashboard.finance');
    }

    public function data(User $user): array
    {
        $from = now()->startOfMonth()->subMonths(11);
        $runs = PayrollRun::whereIn('status', ['approved', 'paid'])->whereDate('period', '>=', $from->toDateString())
            ->get(['id', 'period', 'type', 'total_gross', 'total_net', 'total_employer_cost', 'employee_count'])
            ->groupBy(fn ($r) => $r->period->format('Y-m'));

        $series = collect(range(11, 0))->map(function ($i) use ($runs) {
            $m = now()->startOfMonth()->subMonths($i);
            $g = $runs->get($m->format('Y-m'), collect());

            return [
                'month' => $m->format('M y'),
                'gross' => round((float) $g->sum('total_gross'), 2),
                'net' => round((float) $g->sum('total_net'), 2),
                'employer_cost' => round((float) $g->sum('total_employer_cost'), 2),
                'headcount' => (int) $g->where('type', 'regular')->sum('employee_count'),
            ];
        })->values();

        return ['series' => $series];
    }
}
