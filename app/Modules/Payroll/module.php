<?php

use App\Models\Employee;
use App\Modules\Employees\Events\EmployeeExited;
use App\Modules\Employees\Events\EmployeeHired;
use App\Modules\Employees\Events\EmployeeReinstated;
use App\Modules\Employees\Events\EmployeeTransferred;
use App\Modules\Employees\Support\SideEffects;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollLoan;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Services\PayProfileProvisioner;
use Illuminate\Support\Facades\Event;

return [
    'name' => 'Payroll',
    'order' => 90,

    'approval_providers' => [
        App\Modules\Payroll\Dashboard\Approvals\PayrollRunApprovalProvider::class,
        App\Modules\Payroll\Dashboard\Approvals\SalaryAdvanceApprovalProvider::class,
    ],
    'widgets' => [
        App\Modules\Payroll\Dashboard\Widgets\MyLatestPayslipWidget::class,
        App\Modules\Payroll\Dashboard\Widgets\PayrollRunStatusWidget::class,
        App\Modules\Payroll\Dashboard\Widgets\StatutoryRemittanceWidget::class,
        App\Modules\Payroll\Dashboard\Widgets\PayrollCostTrendWidget::class,
    ],

    'boot' => function () {
        view()->addNamespace('payroll', __DIR__.'/resources/views');
        Employee::resolveRelationUsing('payProfile', fn (Employee $e) => $e->hasOne(PayrollPayProfile::class));
        Employee::resolveRelationUsing('employeeComponents', fn (Employee $e) => $e->hasMany(PayrollEmployeeComponent::class));
        Employee::resolveRelationUsing('payslips', fn (Employee $e) => $e->hasMany(PayrollRunEmployee::class));
        Employee::resolveRelationUsing('loans', fn (Employee $e) => $e->hasMany(PayrollLoan::class));
        Employee::resolveRelationUsing('salaryHistory', fn (Employee $e) => $e->hasMany(PayrollSalaryHistory::class)->orderByDesc('effective_date'));
        Employee::resolveRelationUsing('salaryAdvances', fn (Employee $e) => $e->hasMany(PayrollSalaryAdvance::class));

        // New hire: pay profile seeded with the contract's gross salary (plus an initial salary history row) when known.
        Event::listen(EmployeeHired::class, function (EmployeeHired $event) {
            SideEffects::run('payroll: create pay profile for new hire', function () use ($event) {
                $initial = property_exists($event, 'options') && isset($event->options['basic_salary']) ? (float) $event->options['basic_salary'] : null;
                app(PayProfileProvisioner::class)->forHire($event->employee, $initial);
            });
        });

        // Leaver: the hold only excludes runs whose period starts after the exit date (see PayrollRunService::eligibleEmployees),
        // so the exit month is still paid (prorated) and final dues remain available.
        Event::listen(EmployeeExited::class, function (EmployeeExited $event) {
            SideEffects::run('payroll: hold pay profile on exit', fn () => PayrollPayProfile::withoutGlobalScope('company')
                ->where('employee_id', $event->employee->id)->update(['on_hold' => true]));
        });

        // Re-instated / transferred: the new employee record gets the previous record's pay profile, current basic and components.
        Event::listen(EmployeeReinstated::class, function (EmployeeReinstated $event) {
            SideEffects::run('payroll: carry pay profile to re-instated record',
                fn () => app(PayProfileProvisioner::class)->carryOver($event->employee, $event->previous, 'reinstatement'));
        });
        Event::listen(EmployeeTransferred::class, function (EmployeeTransferred $event) {
            SideEffects::run('payroll: carry pay profile to transferred record',
                fn () => app(PayProfileProvisioner::class)->carryOver($event->employee, $event->previous, 'transfer'));
        });
    },
];
