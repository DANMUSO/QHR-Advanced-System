<?php

namespace App\Modules\Employees\Support;

use App\Models\Employee;
use App\Models\User;

class EmployeeAccess
{
    /** HR (employees.view), the employee themself, or their primary/secondary supervisor. */
    public static function canView(User $user, Employee $e): bool
    {
        if ($user->can('employees.view')) {
            return true;
        }
        $me = $user->employee?->id;

        return $me !== null && ($e->id === $me || $e->primary_supervisor_id === $me || $e->secondary_supervisor_id === $me);
    }

    /** Statutory numbers and family details: HR and the employee only (not supervisors). */
    public static function canSeeSensitive(User $user, Employee $e): bool
    {
        return $user->can('employees.view') || $user->employee?->id === $e->id;
    }
}
