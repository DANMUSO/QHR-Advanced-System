<?php

use App\Models\Employee;
use App\Modules\Employees\Events\EmployeeHired;
use App\Modules\Employees\Events\EmployeeReinstated;
use App\Modules\Employees\Events\EmployeeTransferred;
use App\Modules\Performance\Console\RolloverRecurringKpis;
use App\Modules\Performance\Console\SendKpiReminders;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\Certification;
use App\Modules\Performance\Models\CoachingSession;
use App\Modules\Performance\Models\DiscussionPoint;
use App\Modules\Performance\Models\Enrollment;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Pip;
use App\Modules\Performance\Models\PotentialRating;
use App\Modules\Performance\Models\Recognition;
use App\Modules\Performance\Models\Skill;
use App\Modules\Performance\Models\TalentPool;
use Illuminate\Console\Scheduling\Schedule;

return [
    'name' => 'Performance',
    'order' => 60,

    // KPI results awaiting approval and review requests (dashboard inbox)
    'approval_providers' => [App\Modules\Performance\Dashboard\KpiApprovalProvider::class],

    'boot' => function () {
        \Illuminate\Console\Application::starting(function ($artisan) {
            $artisan->resolve(SendKpiReminders::class);
            $artisan->resolve(RolloverRecurringKpis::class);
        });
        Employee::resolveRelationUsing('goals', fn (Employee $e) => $e->hasMany(Goal::class));
        Employee::resolveRelationUsing('appraisals', fn (Employee $e) => $e->hasMany(Appraisal::class));
        Employee::resolveRelationUsing('enrollments', fn (Employee $e) => $e->hasMany(Enrollment::class));
        Employee::resolveRelationUsing('coachingSessions', fn (Employee $e) => $e->hasMany(CoachingSession::class));
        Employee::resolveRelationUsing('pips', fn (Employee $e) => $e->hasMany(Pip::class));
        Employee::resolveRelationUsing('recognitions', fn (Employee $e) => $e->hasMany(Recognition::class));
        Employee::resolveRelationUsing('talentPools', fn (Employee $e) => $e->belongsToMany(TalentPool::class, 'succ_talent_pool_members'));
        Employee::resolveRelationUsing('discussionPoints', fn (Employee $e) => $e->hasMany(DiscussionPoint::class));
        Employee::resolveRelationUsing('certifications', fn (Employee $e) => $e->hasMany(Certification::class));
        Employee::resolveRelationUsing('skills', fn (Employee $e) => $e->belongsToMany(Skill::class, 'ld_employee_skills')->withPivot('level'));
        Employee::resolveRelationUsing('potentialRatings', fn (Employee $e) => $e->hasMany(PotentialRating::class));

        // Hire, transfer into another company or re-instatement: enrol the new record in its company's current cycle.
        \Illuminate\Support\Facades\Event::listen([EmployeeHired::class, EmployeeTransferred::class, EmployeeReinstated::class], function ($event) {
            \App\Modules\Employees\Support\SideEffects::run('performance: enrol new employment in current cycle', function () use ($event) {
                $employee = $event->employee;
                // Enrol into the employee's own company's active cycle (not the viewer's company).
                $cycle = \App\Modules\Performance\Models\Cycle::current($employee->company_id);
                if (! $cycle) {
                    return;
                }
                $exists = Appraisal::withoutGlobalScope('company')
                    ->where('employee_id', $employee->id)->where('cycle_id', $cycle->id)->exists();
                if (! $exists) {
                    // company_id is guarded, so it must be force-filled; otherwise the tenancy hook
                    // stamps the viewer's company (null for a super admin viewing another company).
                    (new Appraisal(['employee_id' => $employee->id, 'cycle_id' => $cycle->id, 'status' => 'pending']))
                        ->forceFill(['company_id' => $employee->company_id])->save();
                }
            });
        });
    },

    'approval_providers' => [\App\Modules\Performance\Dashboard\KpiApprovalProvider::class],
    'widgets' => [App\Modules\Performance\Dashboard\MyKpiProgressWidget::class],

    'schedule' => function (Schedule $s) {
        // Daily KPI 7/3/1-day reminders (§12)
        $s->command('performance:kpi-reminders')->dailyAt('08:00')->withoutOverlapping();
        // Daily recurring KPI rollover (§12)
        $s->command('performance:kpi-rollover')->dailyAt('00:15')->withoutOverlapping();
    },
];
