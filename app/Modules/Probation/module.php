<?php

use App\Models\Employee;
use App\Modules\Employees\Events\EmployeeHired;
use App\Modules\Employees\Events\EmployeeReinstated;
use App\Modules\Employees\Events\EmployeeTransferred;
use App\Modules\Employees\Support\SideEffects;
use App\Modules\Probation\Console\AlertProbationEnding;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Services\ProbationRouting;
use Illuminate\Console\Scheduling\Schedule;

return [
    'name' => 'Probation',
    'order' => 70,

    'boot' => function () {
        Employee::resolveRelationUsing('probationReview', fn (Employee $e) => $e->hasOne(ProbationReview::class)->latestOfMany());

        \Illuminate\Console\Application::starting(function ($artisan) {
            $artisan->resolve(AlertProbationEnding::class);
        });

        // Any new employment period (hire, transfer into another company, re-instatement) that starts on probation.
        \Illuminate\Support\Facades\Event::listen([EmployeeHired::class, EmployeeTransferred::class, EmployeeReinstated::class], function ($event) {
            SideEffects::run('probation: open review for new employment', function () use ($event) {
                $employee = $event->employee;
                if (! $employee->probation_end_date || $employee->probation_status !== 'on_probation') {
                    return;
                }
                if (ProbationReview::withoutGlobalScope('company')->where('employee_id', $employee->id)->exists()) {
                    return;
                }
                // Routed to the first stage's reviewer (escalating past people without a usable login).
                app(ProbationRouting::class)->open($employee, notify: false);
            });
        });

        // probation_months / date_of_joining edits move the employee's end date: keep the open review in step.
        Employee::saved(function (Employee $employee) {
            if ($employee->wasChanged('probation_end_date') && $employee->probation_end_date) {
                ProbationReview::withoutGlobalScopes()->where('employee_id', $employee->id)
                    ->whereNotIn('status', ProbationRouting::FINAL)
                    ->update(['probation_end_date' => $employee->probation_end_date->toDateString()]);
            }
        });
    },

    'approval_providers' => [App\Modules\Probation\Dashboard\ProbationApprovals::class],

    'schedule' => function (Schedule $s) {
        // Daily 7/3/1-day probation ending alerts (§12)
        $s->command('probation:alert')->dailyAt('07:30')->withoutOverlapping();
    },
];
