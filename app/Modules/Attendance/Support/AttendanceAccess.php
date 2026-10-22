<?php

namespace App\Modules\Attendance\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see whose attendance.
 *  - attendance.manage: everyone in the (scoped) company
 *  - attendance.view-team: own direct reports (primary or secondary supervisor)
 *  - everyone: themselves
 */
class AttendanceAccess
{
    public static function managesAll(User $user): bool
    {
        return $user->can('attendance.manage');
    }

    /** Active employees the user oversees (company for HR, direct reports for supervisors). */
    public static function teamQuery(User $user): Builder
    {
        $q = Employee::query()->active();
        if (static::managesAll($user)) {
            return $q;
        }
        $me = $user->employee;
        if (! $me || ! $user->can('attendance.view-team')) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(fn ($w) => $w->where('primary_supervisor_id', $me->id)->orWhere('secondary_supervisor_id', $me->id));
    }

    public static function canView(User $user, Employee $employee): bool
    {
        if ($user->employee?->id === $employee->id || static::managesAll($user)) {
            return true;
        }
        $me = $user->employee;

        return $me && $user->can('attendance.view-team')
            && ($employee->primary_supervisor_id === $me->id || $employee->secondary_supervisor_id === $me->id);
    }

    /** May decide (approve/reject) overtime and timesheets of this employee. Nobody decides their own. */
    public static function canDecide(User $user, Employee $employee): bool
    {
        if ($user->employee?->id === $employee->id) {
            return false;
        }

        return static::canView($user, $employee) && ($user->can('attendance.view-team') || static::managesAll($user));
    }
}
