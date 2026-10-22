<?php

namespace App\Modules\Payroll\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollRun;

/** Current payroll run status for Finance/Payroll dashboard. */
class PayrollRunStatusWidget extends Widget
{
    public function key(): string   { return 'payroll_run_status'; }
    public function title(): string { return 'Payroll run status'; }
    public function size(): string  { return 'sm'; }

    public function visible(User $user): bool
    {
        return $user->can('dashboard.finance');
    }

    public function data(User $user): array
    {
        $runs = PayrollRun::whereNotIn('status', ['paid'])
            ->orderByDesc('period')
            ->limit(3)
            ->get(['id', 'title', 'period', 'status', 'type', 'total_net', 'employee_count', 'needs_recalculation', 'pay_date']);

        $latest = $runs->first();

        return [
            'current' => $latest ? [
                'id'                  => $latest->id,
                'title'               => $latest->title,
                'period'              => $latest->period?->format('F Y'),
                'status'              => $latest->status,
                'type'                => $latest->type,
                'total_net'           => (float) $latest->total_net,
                'employee_count'      => (int) $latest->employee_count,
                'needs_recalculation' => (bool) $latest->needs_recalculation,
                'pay_date'            => $latest->pay_date?->toDateString(),
            ] : null,
            'open_runs' => $runs->count(),
            'all'       => $runs->map(fn ($r) => [
                'id' => $r->id, 'title' => $r->title, 'status' => $r->status,
                'total_net' => (float) $r->total_net,
            ])->values(),
        ];
    }
}
