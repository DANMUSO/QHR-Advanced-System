<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\User;
use Carbon\Carbon;

class HeadcountKpisWidget extends Widget
{
    public function key(): string
    {
        return 'headcount_kpis';
    }

    public function title(): string
    {
        return 'Workforce at a glance';
    }

    public function size(): string
    {
        return 'full';
    }

    public function visible(User $user): bool
    {
        return $user->can('dashboard.hr');
    }

    public function data(User $user): array
    {
        $active = Employee::active();
        $total = (clone $active)->count();
        $monthStart = now()->startOfMonth();

        $joiners = Employee::where('date_of_joining', '>=', $monthStart)
            ->whereNotIn('status', ['transferred'])
            ->whereNull('previous_employee_id')
            ->count();
        $leavers = EmployeeExit::whereIn('employee_id', Employee::withTrashed()->select('id'))
            ->where('exit_date', '>=', $monthStart)->count();
        $leavers12 = EmployeeExit::whereIn('employee_id', Employee::withTrashed()->select('id'))
            ->where('exit_date', '>=', now()->subYear())->count();

        $lastMonthTotal = Employee::where('date_of_joining', '<', $monthStart)
            ->where(fn ($q) => $q->where('status', 'active')
                ->orWhereHas('exitRecord', fn ($e) => $e->where('exit_date', '>=', $monthStart)))
            ->count();

        $byGender = (clone $active)->selectRaw("COALESCE(gender, 'unspecified') as g, count(*) c")->groupBy('g')->pluck('c', 'g');
        // Ages and tenure are averaged in PHP so the query stays portable (MySQL in production, sqlite in tests).
        $dates = (clone $active)->get(['date_of_birth', 'date_of_joining']);
        $avgAge = $dates->whereNotNull('date_of_birth')->avg(fn ($e) => Carbon::parse($e->date_of_birth)->diffInYears(now()));
        $avgTenure = $dates->whereNotNull('date_of_joining')->avg(fn ($e) => Carbon::parse($e->date_of_joining)->diffInMonths(now()));

        return [
            'headcount' => $total,
            'headcount_change' => $total - $lastMonthTotal,
            'joiners_this_month' => $joiners,
            'leavers_this_month' => $leavers,
            'turnover_rate' => $total > 0 ? round($leavers12 / max(1, ($total + $leavers12 / 2)) * 100, 1) : 0,
            'gender' => $byGender,
            'average_age' => $avgAge ? round($avgAge, 1) : null,
            'average_tenure_years' => $avgTenure ? round($avgTenure / 12, 1) : null,
            'on_probation' => (clone $active)->whereIn('probation_status', ['on_probation', 'extended'])->count(),
        ];
    }
}
