<?php

namespace App\Http\Controllers;

use App\Models\Benefit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\Position;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dropdown data shared by every module: GET /api/lookups?only=branches,departments,employees
 * Scoped to the current company (super admins: X-Company-Id header, or ?company_id=).
 */
class LookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $only = array_filter(explode(',', (string) $request->query('only', 'branches,departments,positions,levels,benefits')));
        $companyId = $request->integer('company_id') ?: null;
        $scope = fn ($q) => $companyId && Tenancy::isGlobal() ? $q->withoutGlobalScope('company')->where('company_id', $companyId) : $q;

        $data = [];
        foreach ($only as $key) {
            $data[$key] = match ($key) {
                'companies' => Tenancy::isGlobal()
                    ? Company::orderBy('name')->get(['id', 'name'])
                    : Company::whereKey($request->user()->company_id)->get(['id', 'name']),
                'branches' => $scope(Branch::query())->orderBy('name')->get(['id', 'name', 'company_id']),
                'departments' => $scope(Department::query())->orderBy('name')->get(['id', 'name', 'company_id']),
                'positions' => $scope(Position::query())->orderBy('title')->get(['id', 'title', 'is_supervisor', 'employment_level_id', 'company_id']),
                'levels' => $scope(EmploymentLevel::query())->orderBy('name')->get(['id', 'name', 'probation_months', 'company_id']),
                'benefits' => $scope(Benefit::query())->orderBy('name')->get(['id', 'name', 'company_id']),
                'employees' => $scope(Employee::query())->active()->orderBy('first_name')
                    ->get(['id', 'first_name', 'last_name', 'employee_number', 'department_id', 'position_id', 'photo_path', 'company_id'])
                    ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number,
                        'department_id' => $e->department_id, 'position_id' => $e->position_id, 'photo_url' => $e->photo_url]),
                'supervisors' => $scope(Employee::query())->active()
                    ->where(fn ($q) => $q->whereHas('position', fn ($p) => $p->where('is_supervisor', true))->orWhereHas('directReports'))
                    ->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                    ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name]),
                default => [],
            };
        }

        return response()->json($data);
    }
}
