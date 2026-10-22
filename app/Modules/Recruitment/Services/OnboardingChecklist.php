<?php

namespace App\Modules\Recruitment\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Support\ApproverResolver;
use App\Modules\Recruitment\Models\InductionTask;
use App\Modules\Recruitment\Models\OnboardingTask;
use App\Modules\Recruitment\Notifications\RecruitmentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Builds a new hire's onboarding checklist from the company's induction task templates (on EmployeeHired) and
 * tells each task owner what they need to do. Idempotent: existing tasks are kept.
 */
class OnboardingChecklist
{
    /** Template category → permission whose holders own the task when no matching role exists. */
    public const OWNER_PERMISSIONS = [
        'hr' => 'employees.create', 'it' => 'users.manage', 'finance' => 'payroll.manage', 'security' => 'employees.update',
    ];

    public function __construct(private ApproverResolver $resolver) {}

    /** @return int tasks created */
    public function create(Employee $employee, bool $notify = true): int
    {
        $templates = InductionTask::withoutGlobalScopes()->where('company_id', $employee->company_id)->orderBy('due_days')->get();
        $created = collect();
        foreach ($templates as $t) {
            $task = OnboardingTask::withoutGlobalScopes()->firstOrNew(['employee_id' => $employee->id, 'induction_task_id' => $t->id]);
            if ($task->exists) {
                continue;
            }
            $task->fill(['name' => $t->name, 'category' => $t->category, 'is_mandatory' => $t->is_mandatory,
                'due_date' => ($employee->date_of_joining ?? now())->copy()->addDays($t->due_days)]);
            $task->company_id = $employee->company_id;
            $task->save();
            $created->push([$task, $t]);
        }

        if ($notify && $created->isNotEmpty()) {
            $this->notifyOwners($employee, $created);
        }

        return $created->count();
    }

    /** One message per owner, listing that owner's tasks. */
    private function notifyOwners(Employee $employee, Collection $created): void
    {
        $byOwner = [];
        foreach ($created as [$task, $template]) {
            foreach ($this->owners($employee, $template) as $user) {
                $byOwner[$user->id]['user'] = $user;
                $byOwner[$user->id]['tasks'][] = $task->name.' (due '.$task->due_date?->format('d M Y').')';
            }
        }
        foreach ($byOwner as $row) {
            try {
                Notification::send($row['user'], new RecruitmentNotification(
                    "Onboarding tasks for {$employee->full_name}",
                    "{$employee->full_name} joins on ".($employee->date_of_joining?->format('d M Y') ?? 'soon').'. Your tasks: '.implode('; ', $row['tasks']).'.',
                    '/recruitment?tab=onboarding',
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Owners: the hire's supervisor for manager tasks; else users holding the owner role, else the category's permission. */
    private function owners(Employee $employee, InductionTask $template): Collection
    {
        $category = $template->owner_role ?: $template->category;
        if ($category === 'manager') {
            $supervisor = $this->resolver->resolve($employee->primary_supervisor_id, [$employee->id]);

            return $supervisor?->user ? collect([$supervisor->user]) : $this->resolver->fallbackUsers($employee->company_id, 'employees.create');
        }

        $users = collect();
        try {
            $users = User::where('company_id', $employee->company_id)->where('is_active', true)->role($category)->get();
        } catch (\Throwable) {
            // not a role name
        }

        return $users->isNotEmpty() ? $users
            : $this->resolver->fallbackUsers($employee->company_id, self::OWNER_PERMISSIONS[$category] ?? 'employees.create', [$employee->user_id]);
    }
}
