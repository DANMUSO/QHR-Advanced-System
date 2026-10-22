<?php

namespace App\Modules\Performance\Dashboard;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Performance\Models\Goal;

/** The signed-in employee's KPI progress summary. */
class MyKpiProgressWidget extends Widget
{
    public function key(): string   { return 'my_kpi_progress'; }
    public function title(): string { return 'My KPI progress'; }
    public function size(): string  { return 'sm'; }

    public function visible(User $user): bool
    {
        return $user->employee !== null && $user->can('performance.participate');
    }

    public function data(User $user): array
    {
        $goals = Goal::withoutGlobalScopes()
            ->where('employee_id', $user->employee->id)
            ->whereIn('status', ['cascaded', 'in_progress', 'submitted', 'approved', 'under_review'])
            ->with('kpi:id,name')
            ->orderBy('due_date')
            ->limit(50)
            ->get();

        $total    = $goals->count();
        $approved = $goals->where('status', 'approved')->count();
        $pending  = $goals->whereIn('status', ['cascaded', 'in_progress', 'under_review'])->count();
        $submitted = $goals->where('status', 'submitted')->count();
        $overdue  = $goals->filter(fn ($g) => $g->due_date && $g->due_date->isPast() && $g->status !== 'approved')->count();

        return [
            'total'     => $total,
            'approved'  => $approved,
            'submitted' => $submitted,
            'pending'   => $pending,
            'overdue'   => $overdue,
            'pct'       => $total > 0 ? round($approved / $total * 100) : 0,
            'items'     => $goals->take(5)->map(fn ($g) => [
                'id'       => $g->id,
                'name'     => $g->kpi?->name ?? 'Goal',
                'status'   => $g->status,
                'due_date' => $g->due_date?->toDateString(),
                'target'   => $g->target,
                'achieved' => $g->achieved,
            ])->values(),
        ];
    }
}
