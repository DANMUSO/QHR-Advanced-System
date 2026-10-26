<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class RecentActivityWidget extends Widget
{
    public function key(): string
    {
        return 'recent_activity';
    }

    public function title(): string
    {
        return 'Recent activity';
    }

    public function size(): string
    {
        return 'md';
    }

    public function data(User $user): array
    {
        $query = Activity::with('causer:id,name')->latest()->limit(10);

        if (! $user->can('audit.view')) {
            // Employees see their own actions and changes made to their own records.
            $query->where(fn ($q) => $q->where(fn ($c) => $c->where('causer_type', User::class)->where('causer_id', $user->id))
                ->orWhere(fn ($s) => $s->where('subject_type', User::class)->where('subject_id', $user->id))
                ->when($user->employee, fn ($w) => $w->orWhere(fn ($s) => $s->where('subject_type', \App\Models\Employee::class)->where('subject_id', $user->employee->id))));
        } elseif (! $user->hasRole('super-admin')) {
            \App\Http\Controllers\AuditController::scopeToCompany($query, (int) $user->company_id);
        }

        $subjectLabels = [
            'Employee' => 'employee', 'User' => 'user account', 'LeaveRequest' => 'leave request',
            'MedicalClaim' => 'medical claim', 'PayrollRun' => 'payroll run', 'AttendanceDay' => 'attendance record',
            'EngagementComment' => 'comment', 'PayrollLoan' => 'loan', 'EmployeeContract' => 'contract',
        ];

        return [
            'items' => $query->get()->map(fn (Activity $a) => [
                'id' => $a->id,
                'description' => $a->description,
                'log' => $a->log_name,
                'event' => $a->event,
                'subject' => $subjectLabels[class_basename((string) $a->subject_type)] ?? strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename((string) $a->subject_type))),
                'causer' => \App\Support\ActivityCauser::label($a),
                'at' => $a->created_at,
            ]),
        ];
    }
}
