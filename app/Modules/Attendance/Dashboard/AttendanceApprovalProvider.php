<?php

namespace App\Modules\Attendance\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Models\AttendanceTimesheet;
use App\Modules\Attendance\Support\AttendanceAccess;
use Illuminate\Database\Eloquent\Builder;

/** Pending overtime claims and submitted timesheets for the dashboard approvals inbox. */
class AttendanceApprovalProvider implements ApprovalProvider
{
    private function scope(User $user, Builder $q): Builder
    {
        $q->where('employee_id', '!=', $user->employee?->id ?? 0);
        if (! AttendanceAccess::managesAll($user)) {
            $q->whereIn('employee_id', AttendanceAccess::teamQuery($user)->select('id'));
        }

        return $q;
    }

    private function relevant(User $user): bool
    {
        return $user->can('attendance.view-team') || $user->can('attendance.manage');
    }

    public function pendingFor(User $user, int $limit = 10): array
    {
        if (! $this->relevant($user)) {
            return [];
        }
        $overtime = $this->scope($user, AttendanceOvertime::query()->where('status', 'pending'))
            ->with('employee:id,first_name,last_name')->latest('date')->limit($limit)->get()
            ->map(fn ($o) => [
                'id' => 'ot-'.$o->id,
                'type' => 'overtime',
                'title' => ($o->employee?->full_name ?? 'Employee').' · '.$o->hours.' h overtime',
                'subtitle' => $o->date->format('D j M').($o->day_type !== 'working' ? ' ('.$o->day_type.')' : ''),
                'requested_at' => $o->created_at,
                'url' => '/attendance/manage?tab=overtime',
                'approve_endpoint' => "/attendance/overtime/{$o->id}/approve",
                'reject_endpoint' => "/attendance/overtime/{$o->id}/reject",
            ]);
        $sheets = $this->scope($user, AttendanceTimesheet::query()->where('status', 'submitted'))
            ->with('employee:id,first_name,last_name')->latest('submitted_at')->limit($limit)->get()
            ->map(fn ($t) => [
                'id' => 'ts-'.$t->id,
                'type' => 'timesheet',
                'title' => ($t->employee?->full_name ?? 'Employee').' · timesheet',
                'subtitle' => $t->week_start->format('j M').' – '.$t->week_start->copy()->addDays(6)->format('j M').' · '.round($t->worked_minutes / 60, 1).' h'
                    .($t->incomplete_days ? ' · '.$t->incomplete_days.' missing clock-out' : '').($t->absent_days ? ' · '.$t->absent_days.' absent' : ''),
                'requested_at' => $t->submitted_at,
                'url' => '/attendance/manage?tab=timesheets',
                // Opened and reviewed day by day, not approved blind from the inbox.
                'approve_endpoint' => null,
                'reject_endpoint' => null,
            ]);

        return $overtime->concat($sheets)->sortByDesc('requested_at')->take($limit)->values()->all();
    }

    public function countFor(User $user): int
    {
        if (! $this->relevant($user)) {
            return 0;
        }

        return $this->scope($user, AttendanceOvertime::query()->where('status', 'pending'))->count()
            + $this->scope($user, AttendanceTimesheet::query()->where('status', 'submitted'))->count();
    }
}
