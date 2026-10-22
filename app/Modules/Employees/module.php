<?php

use App\Models\Employee;
use App\Modules\Employees\Console\ProcessLifecycle;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Models\ExitRecord;
use App\Modules\Employees\Models\EmployeeTransfer;
use App\Modules\Employees\Models\KycCheck;

/*
| Employees (Core HR): directory, onboarding, profiles, self-service, contracts, letters (PDF), KYC,
| internal moves, exits (with clearance), re-instatement, company transfers, account activation,
| org chart and staff report. Live pay is owned by the Payroll module.
|
| Other modules can react to the employee lifecycle by listening to (dispatched after commit):
|   App\Modules\Employees\Events\EmployeeHired       ($employee)
|   App\Modules\Employees\Events\EmployeeExited      ($employee, $exit)
|   App\Modules\Employees\Events\EmployeeReinstated  ($employee, $previous)
|   App\Modules\Employees\Events\EmployeeTransferred ($employee, $previous)   moved to another company
*/
return [
    'name' => 'Employees',
    'order' => 10,

    // Uses the core permission keys from config/hr.php (employees.view/create/update/exit/transfer/view-salary).
    'permissions' => [],
    'role_permissions' => [],

    'boot' => function () {
        view()->addNamespace('employees', __DIR__.'/resources/views');

        // A recorded exit closes the employee's pending self-service resignation and links it to the exit.
        \Illuminate\Support\Facades\Event::listen(\App\Modules\Employees\Events\EmployeeExited::class,
            fn ($event) => app(\App\Modules\Employees\Services\SelfServiceRequestService::class)->markExited($event->employee, $event->exit->id));

        // Someone joins (hire, transfer in, re-instatement): tell their new supervisor, and on a transfer also the
        // receiving company's HR, who did not record the move themselves.
        \Illuminate\Support\Facades\Event::listen([
            \App\Modules\Employees\Events\EmployeeHired::class,
            \App\Modules\Employees\Events\EmployeeTransferred::class,
            \App\Modules\Employees\Events\EmployeeReinstated::class,
        ], function ($event) {
            \App\Modules\Employees\Support\SideEffects::run('employees: announce joiner', function () use ($event) {
                $e = $event->employee;
                $how = match (true) {
                    $event instanceof \App\Modules\Employees\Events\EmployeeTransferred => 'transfers in',
                    $event instanceof \App\Modules\Employees\Events\EmployeeReinstated => 'returns',
                    default => 'joins',
                };
                $when = $e->date_of_joining ? ' on '.\Illuminate\Support\Carbon::parse($e->date_of_joining)->format('j M Y') : '';
                $role = $e->position?->name ? ' as '.$e->position->name : '';
                $notice = fn () => new \App\Modules\Employees\Notifications\EmployeeNotification(
                    "{$e->full_name} {$how}", "{$e->full_name} {$how}{$role}{$when}.", "/employees/{$e->id}", 'user-plus');

                $supervisorUser = $e->primary_supervisor_id
                    ? Employee::withoutGlobalScopes()->find($e->primary_supervisor_id)?->user : null;
                $supervisorUser?->notify($notice());

                if ($event instanceof \App\Modules\Employees\Events\EmployeeTransferred) {
                    \App\Models\User::where('company_id', $e->company_id)->where('is_active', true)
                        ->permission('employees.update')->when($supervisorUser, fn ($q) => $q->whereKeyNot($supervisorUser->id))
                        ->get()->each->notify($notice());
                }
            });
        });

        // An approver who could not act (no activated login) skips to the next person or HR. When they activate,
        // route their waiting items to them now instead of at the nightly approvals:reroute run.
        \App\Models\User::updated(function (\App\Models\User $user) {
            if ($user->wasChanged('activated_at') && $user->activated_at && ! $user->getOriginal('activated_at')) {
                dispatch(fn () => \App\Modules\Employees\Support\SideEffects::run('employees: reroute approvals on activation',
                    fn () => app(\App\Modules\Employees\Support\ApprovalRerouter::class)->run()))->afterResponse();
            }
        });

        // The record that superseded this one (re-instatement or transfer), across companies.
        Employee::resolveRelationUsing('successorRecord', fn (Employee $e) => $e->hasOne(Employee::class, 'previous_employee_id')
            ->withoutGlobalScope('company'));
        Employee::resolveRelationUsing('contracts', fn (Employee $e) => $e->hasMany(EmployeeContract::class));
        Employee::resolveRelationUsing('moves', fn (Employee $e) => $e->hasMany(EmployeeTransfer::class));
        Employee::resolveRelationUsing('kycChecks', fn (Employee $e) => $e->hasMany(KycCheck::class));
        Employee::resolveRelationUsing('exitRecord', fn (Employee $e) => $e->hasOne(ExitRecord::class)->latestOfMany());
        // A future-dated exit not yet applied (directory "Leaving" badge).
        Employee::resolveRelationUsing('scheduledExit', fn (Employee $e) => $e->hasOne(ExitRecord::class)->where('status', 'scheduled'));

        \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(ProcessLifecycle::class));
        // approvals:reroute — move pending approvals off approvers who cannot log in (Support\ApproverResolver).
        \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(\App\Modules\Employees\Console\RerouteApprovals::class));

        // routes/api.php registers placeholder StubController routes for employees/{contracts,letters,transfers,exits,kyc}
        // AFTER the module route files, which would shadow the real endpoints. Re-register this module's routes once the
        // app has booted so the real controllers win. Harmless (identical re-registration) once those stubs are removed.
        app()->booted(function () {
            if (! app()->routesAreCached()) {
                \Illuminate\Support\Facades\Route::prefix('api')->middleware(['api', 'auth:sanctum', 'account.secure'])
                    ->group(__DIR__.'/routes.php');
            }
        });
    },

    'schedule' => function (\Illuminate\Console\Scheduling\Schedule $s) {
        $s->command('employees:process-lifecycle')->dailyAt('00:15');
        // Accounts get deactivated / exits processed at any time; re-route approvals left with unreachable approvers.
        $s->command('approvals:reroute')->dailyAt('00:40')->withoutOverlapping();
    },

    // Self-service requests awaiting HR (profile changes, letter requests, resignations).
    'approval_providers' => [App\Modules\Employees\Dashboard\StaffRequestApprovals::class],

    'demo_seeder' => App\Modules\Employees\Database\DemoSeeder::class,
];
