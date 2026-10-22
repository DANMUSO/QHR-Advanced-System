<?php

namespace App\Modules\Performance\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may see whose performance data:
 *  - everyone sees their own records
 *  - supervisors see everyone in their reporting tree (direct and indirect reports)
 *  - performance.admin (HR) sees the whole company
 */
class PerformanceAccess
{
    /** @var array<int, Collection> */
    private static array $treeCache = [];

    public static function isAdmin(User $user): bool
    {
        return $user->can('performance.admin');
    }

    /** IDs of every employee below $employeeId in the reporting tree. */
    public static function subordinateIds(int $employeeId, int $maxDepth = 8): Collection
    {
        if (isset(self::$treeCache[$employeeId])) {
            return self::$treeCache[$employeeId];
        }
        $all = collect();
        $level = collect([$employeeId]);
        for ($depth = 0; $depth < $maxDepth && $level->isNotEmpty(); $depth++) {
            $level = Employee::withoutGlobalScopes()->whereIn('primary_supervisor_id', $level)->where('status', 'active')
                ->pluck('id')->diff($all)->diff([$employeeId]);
            $all = $all->merge($level);
        }

        return self::$treeCache[$employeeId] = $all->values();
    }

    public static function directReportIds(int $employeeId): Collection
    {
        return Employee::withoutGlobalScopes()->where('primary_supervisor_id', $employeeId)->where('status', 'active')->pluck('id');
    }

    public static function canView(User $user, int $employeeId): bool
    {
        if (self::isAdmin($user) || $user->employee?->id === $employeeId) {
            return true;
        }
        if ($user->can('employees.view')) {
            return true;
        }

        return $user->employee && self::subordinateIds($user->employee->id)->contains($employeeId);
    }

    /** Supervisor actions (coaching, PIP, appraisal) on an employee: anyone above them in the tree, or HR. */
    public static function canManage(User $user, int $employeeId): bool
    {
        if (self::isAdmin($user)) {
            return true;
        }

        return $user->employee && $user->employee->id !== $employeeId
            && self::subordinateIds($user->employee->id)->contains($employeeId);
    }

    /** Employee ids a list endpoint should cover for this user ("team" scope). */
    public static function teamIds(User $user): ?Collection
    {
        if (self::isAdmin($user)) {
            return null; // whole company
        }

        return $user->employee ? self::subordinateIds($user->employee->id) : collect();
    }

    public static function flush(): void
    {
        self::$treeCache = [];
    }
}
