<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Employee;
use App\Models\User;

class WorkforceBreakdownWidget extends Widget
{
    public function key(): string
    {
        return 'workforce_breakdown';
    }

    public function title(): string
    {
        return 'Workforce breakdown';
    }

    public function visible(User $user): bool
    {
        return $user->can('dashboard.hr');
    }

    public function data(User $user): array
    {
        $active = fn () => Employee::active();

        return [
            'by_department' => $active()->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw("COALESCE(departments.name, 'Unassigned') label, count(*) value")
                ->groupBy('label')->orderByDesc('value')->limit(8)->get(),
            'by_contract' => $active()->selectRaw('contract_type label, count(*) value')->groupBy('label')->get(),
            'by_branch' => $active()->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
                ->selectRaw("COALESCE(branches.name, 'Unassigned') label, count(*) value")
                ->groupBy('label')->orderByDesc('value')->limit(8)->get(),
        ];
    }
}
