<?php

namespace App\Modules\Employees\Support;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Directory / report filtering shared by the list, CSV export and staff report.
 */
class EmployeeQuery
{
    public const AGE_BRACKETS = [
        'under_25' => [0, 24], '25_34' => [25, 34], '35_44' => [35, 44], '45_54' => [45, 54], '55_plus' => [55, 200],
    ];

    public const SORTS = ['name', 'employee_number', 'date_of_joining', 'department', 'position', 'branch', 'age', 'status', 'exit_date'];

    public static function build(Request $request, ?string $defaultStatus = 'active'): Builder
    {
        $q = Employee::query()->with([
            'branch:id,name', 'department:id,name', 'position:id,title,is_supervisor',
            'primarySupervisor:id,first_name,last_name,photo_path', 'user:id,is_active,activated_at,last_login_at',
        ]);

        $status = $request->query('status', $defaultStatus);
        if ($status && $status !== 'all') {
            $q->whereIn('employees.status', explode(',', $status));
        }

        $q->search($request->query('search'))
            ->when($request->query('branch_id'), fn ($w, $v) => $w->whereIn('branch_id', (array) $v))
            ->when($request->query('department_id'), fn ($w, $v) => $w->whereIn('department_id', (array) $v))
            ->when($request->query('position_id'), fn ($w, $v) => $w->whereIn('position_id', (array) $v))
            ->when($request->query('employment_level_id'), fn ($w, $v) => $w->where('employment_level_id', $v))
            ->when($request->query('contract_type'), fn ($w, $v) => $w->where('contract_type', $v))
            ->when($request->query('gender'), fn ($w, $v) => $w->where('gender', $v))
            ->when($request->query('probation_status'), fn ($w, $v) => $w->where('probation_status', $v))
            ->when($request->query('joined_from'), fn ($w, $v) => $w->whereDate('date_of_joining', '>=', $v))
            ->when($request->query('joined_to'), fn ($w, $v) => $w->whereDate('date_of_joining', '<=', $v))
            ->when($request->query('supervisor_id'), fn ($w, $v) => $w->where('primary_supervisor_id', $v));

        if ($bracket = self::AGE_BRACKETS[$request->query('age_bracket')] ?? null) {
            $q->whereNotNull('date_of_birth')
                ->whereRaw('TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN ? AND ?', $bracket);
        }

        match ($request->query('account')) {
            'none' => $q->whereNull('user_id'),
            'not_activated' => $q->whereHas('user', fn ($u) => $u->whereNull('activated_at')),
            'activated' => $q->whereHas('user', fn ($u) => $u->whereNotNull('activated_at')),
            default => null,
        };
        if ($request->query('supervisor') === 'none') {
            $q->whereNull('primary_supervisor_id');
        }

        self::sort($q, (string) $request->query('sort', 'name'), $request->query('dir') === 'desc' ? 'desc' : 'asc');

        return $q;
    }

    public static function sort(Builder $q, string $sort, string $dir): void
    {
        match ($sort) {
            'employee_number' => $q->orderBy('employee_number', $dir),
            'date_of_joining' => $q->orderBy('date_of_joining', $dir),
            'status' => $q->orderBy('employees.status', $dir),
            'age' => $q->orderBy('date_of_birth', $dir === 'asc' ? 'desc' : 'asc'),
            'department' => $q->orderBy(Department::withoutGlobalScopes()->select('name')->whereColumn('departments.id', 'employees.department_id'), $dir),
            'branch' => $q->orderBy(Branch::withoutGlobalScopes()->select('name')->whereColumn('branches.id', 'employees.branch_id'), $dir),
            'position' => $q->orderBy(Position::withoutGlobalScopes()->select('title')->whereColumn('positions.id', 'employees.position_id'), $dir),
            'exit_date' => $q->orderBy(\App\Models\EmployeeExit::select('exit_date')->whereColumn('employee_exits.employee_id', 'employees.id')->latest('id')->limit(1), $dir),
            default => $q->orderBy('first_name', $dir)->orderBy('last_name', $dir),
        };
        $q->orderBy('employees.id');
    }

    /** Restricts a query to what the user may see: everyone with employees.view, else their direct reports. */
    public static function scopeForViewer(Builder $q, User $user, bool $teamOnly = false): Builder
    {
        if ($teamOnly || ! $user->can('employees.view')) {
            $q->where('primary_supervisor_id', $user->employee?->id ?? 0);
        }

        return $q;
    }
}
