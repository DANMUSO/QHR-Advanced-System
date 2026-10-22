<?php

namespace App\Modules\Employees\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Shared approver routing used by Leave (handover + supervisor), Claims, Performance (KPI submissions)
 * and Probation reviews.
 *
 * An employee can act as an approver only when they are "reachable": the employee record is active and
 * has a login that is active (is_active). An account still waiting for activation counts: the person receives the
 * item by email and can act as soon as they activate (otherwise new hires' first approvals were silently skipped).
 * When the intended approver is
 * not reachable the item escalates up the reporting line (primary_supervisor_id, repeatedly, loop-guarded)
 * to the first reachable supervisor. When the chain runs out, the resolved approver is null and the item
 * falls back to users in the company holding the module's manage permission.
 */
class ApproverResolver
{
    public const MAX_DEPTH = 15;

    /** Whether the employee can currently log in and act on an approval. */
    public static function isReachable(?Employee $employee): bool
    {
        if (! $employee || $employee->status !== 'active' || ! $employee->user_id) {
            return false;
        }
        $user = $employee->relationLoaded('user') ? $employee->user : User::find($employee->user_id);

        return static::userIsUsable($user);
    }

    public static function userIsUsable(?User $user): bool
    {
        return $user !== null && (bool) $user->is_active;
    }

    /**
     * First reachable employee starting at $employeeId and walking up primary_supervisor_id.
     *
     * @param  array<int>  $exclude  employee ids that may never be the approver (e.g. the applicant); they are skipped, not a dead end
     */
    public function resolve(?int $employeeId, array $exclude = []): ?Employee
    {
        $exclude = array_map('intval', array_filter($exclude));
        $visited = [];
        $id = $employeeId;
        for ($depth = 0; $id && $depth < self::MAX_DEPTH; $depth++) {
            if (isset($visited[$id])) {
                break; // reporting loop
            }
            $visited[$id] = true;
            $employee = Employee::withoutGlobalScopes()->with('user')->find($id);
            if (! $employee) {
                break;
            }
            if (! in_array((int) $employee->id, $exclude, true) && static::isReachable($employee)) {
                return $employee;
            }
            $id = $employee->primary_supervisor_id ? (int) $employee->primary_supervisor_id : null;
        }

        return null;
    }

    public function resolveId(?int $employeeId, array $exclude = []): ?int
    {
        return $this->resolve($employeeId, $exclude)?->id;
    }

    /**
     * Active users of the company holding any of the given permissions (super admins excluded,
     * unless nobody else qualifies).
     *
     * @param  string|array<string>  $permissions
     * @param  array<int>  $excludeUserIds
     */
    public function fallbackUsers(int $companyId, string|array $permissions, array $excludeUserIds = []): Collection
    {
        $permissions = (array) $permissions;
        $base = fn () => User::query()->where('is_active', true)
            ->whereNotIn('id', array_filter($excludeUserIds) ?: [0]);

        $users = collect();
        foreach ($permissions as $permission) {
            try {
                $users = $users->merge($base()->where('company_id', $companyId)->permission($permission)->get());
            } catch (\Throwable $e) {
                report($e); // unknown permission key
            }
        }
        $users = $users->unique('id')->reject(fn (User $u) => $u->hasRole('super-admin'))->values();

        if ($users->isEmpty()) {
            try {
                $users = $base()->role('super-admin')->get();
            } catch (\Throwable) {
                // no super-admin role
            }
        }

        return $users->values();
    }

    /**
     * Users to notify / allowed to act: the resolved approver's login when reachable, else the fallback users.
     */
    public function recipients(?int $approverEmployeeId, int $companyId, string|array $permissions, array $excludeUserIds = []): Collection
    {
        if ($approverEmployeeId) {
            $approver = Employee::withoutGlobalScopes()->with('user')->find($approverEmployeeId);
            if (static::isReachable($approver) && ! in_array($approver->user_id, $excludeUserIds, false)) {
                return collect([$approver->user]);
            }
        }

        return $this->fallbackUsers($companyId, $permissions, $excludeUserIds);
    }

    /** Sub-query: ids of every reachable employee (for whereIn / whereNotIn). */
    public static function reachableEmployeeIds(): \Closure
    {
        return function (QueryBuilder $q) {
            $q->select('employees.id')->from('employees')
                ->join('users', 'users.id', '=', 'employees.user_id')
                ->where('employees.status', 'active')
                ->where('users.is_active', true);
        };
    }

    /** Constrain $q to rows whose approver column is null or points to an unreachable employee. */
    public static function whereNoReachableApprover(Builder|QueryBuilder $q, string $column): Builder|QueryBuilder
    {
        return $q->where(fn ($w) => $w->whereNull($column)->orWhereNotIn($column, static::reachableEmployeeIds()));
    }

    /** Constrain $q to rows whose (non-null) approver column points to an unreachable employee. */
    public static function whereApproverUnreachable(Builder|QueryBuilder $q, string $column): Builder|QueryBuilder
    {
        return $q->whereNotNull($column)->whereNotIn($column, static::reachableEmployeeIds());
    }
}
