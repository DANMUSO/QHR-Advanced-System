<?php

use App\Models\Employee;
use App\Modules\Leave\Console\AccrueLeave;
use App\Modules\Leave\Console\CarryForwardLeave;
use App\Modules\Leave\Console\InitLeaveBalances;
use App\Modules\Leave\Console\RemindPendingLeave;
use App\Modules\Leave\Dashboard\HandoverApprovalProvider;
use App\Modules\Leave\Dashboard\LeaveBalancesWidget;
use App\Modules\Leave\Dashboard\SupervisorApprovalProvider;
use App\Modules\Leave\Dashboard\WhoIsOutWidget;
use App\Modules\Leave\Listeners\OpenBalancesOnMove;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Payroll\LeavePayrollInputs;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;

/*
| Leave: per-company leave types, ledger balances (employee × type × year), the handover → supervisor
| workflow, recalls, monthly accrual / year-end carry-forward jobs, reports and payroll inputs.
| Uses the core permission keys leave.apply, leave.approve, leave.manage, leave.reports.
*/
return [
    'name' => 'Leave',
    'order' => 30,

    'permissions' => [],
    'role_permissions' => [],

    'boot' => function () {
        Employee::resolveRelationUsing('leaveBalances', fn (Employee $e) => $e->hasMany(LeaveBalance::class));
        Employee::resolveRelationUsing('leaveRequests', fn (Employee $e) => $e->hasMany(LeaveRequest::class));

        // A new company starts with the standard leave catalogue (Annual, Sick, Maternity, …), which HR can then edit.
        // Previously it only appeared after the first monthly accrual run, so staff could apply for nothing but custom types.
        \App\Models\Company::created(fn (\App\Models\Company $company) => \App\Modules\Employees\Support\SideEffects::run(
            'leave: default leave types for new company', fn () => \App\Modules\Leave\Models\LeaveType::seedDefaults($company)));

        // New employee record after a company transfer / reinstatement → carried-over or fresh leave balances.
        \Illuminate\Support\Facades\Event::listen('App\Modules\Employees\Events\EmployeeTransferred', [OpenBalancesOnMove::class, 'transferred']);
        \Illuminate\Support\Facades\Event::listen('App\Modules\Employees\Events\EmployeeReinstated', [OpenBalancesOnMove::class, 'reinstated']);

        \Illuminate\Console\Application::starting(function ($artisan) {
            foreach ([AccrueLeave::class, CarryForwardLeave::class, RemindPendingLeave::class, InitLeaveBalances::class] as $command) {
                $artisan->resolve($command);
            }
        });
    },

    'schedule' => function (Schedule $s) {
        $s->command('leave:accrue')->monthlyOn(1, '00:30')->withoutOverlapping();
        $s->command('leave:carry-forward')->yearlyOn(1, 1, '00:45')->withoutOverlapping();
        $s->command('leave:remind')->dailyAt('08:00');
    },

    'widgets' => [WhoIsOutWidget::class, LeaveBalancesWidget::class],
    'approval_providers' => [HandoverApprovalProvider::class, SupervisorApprovalProvider::class],
    'payroll_inputs' => [LeavePayrollInputs::class],

    // My Team widget: direct reports on approved leave today.
    'team_status' => fn (Collection $ids) => LeaveRequest::withoutGlobalScopes()->taken()
        ->whereIn('employee_id', $ids)->overlapping(today()->toDateString(), today()->toDateString())
        ->pluck('employee_id')->mapWithKeys(fn ($id) => [$id => 'on_leave'])->all(),

    // Demo data: Database\Seeders\LeaveDataSeeder (called by ModuleDataSeeder).
];
