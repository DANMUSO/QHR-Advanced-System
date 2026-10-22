<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\User;

class HeadcountTrendWidget extends Widget
{
    public function key(): string
    {
        return 'headcount_trend';
    }

    public function title(): string
    {
        return 'Headcount trend (12 months)';
    }

    public function size(): string
    {
        return 'lg';
    }

    public function visible(User $user): bool
    {
        return $user->can('dashboard.hr');
    }

    public function data(User $user): array
    {
        $employees = Employee::withTrashed()->whereNotNull('date_of_joining')
            ->whereNull('previous_employee_id')
            ->with('exitRecord')->get(['id', 'date_of_joining', 'status']);
        $exits = EmployeeExit::whereIn('employee_id', $employees->pluck('id'))->get(['employee_id', 'exit_date']);

        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));

        $series = $months->map(function ($m) use ($employees, $exits) {
            $end = $m->copy()->endOfMonth();
            $headcount = $employees->filter(function ($e) use ($end) {
                if ($e->date_of_joining->gt($end)) {
                    return false;
                }
                $exit = $e->exitRecord?->exit_date;

                return ! $exit || $exit->gt($end);
            })->count();

            return [
                'month' => $m->format('M y'),
                'headcount' => $headcount,
                'joiners' => $employees->filter(fn ($e) => $e->date_of_joining->between($m, $end))->count(),
                'leavers' => $exits->filter(fn ($x) => $x->exit_date->between($m, $end))->count(),
            ];
        });

        return ['series' => $series->values()];
    }
}
