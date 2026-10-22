<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Support\EmployeeQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff report (HR-FEATURES 2.9): filtered head-count listing with totals. Pay is reported by Payroll. */
class StaffReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employees = EmployeeQuery::build($request)->limit(5000)->get();

        $rows = $employees->map(fn (Employee $e) => [
            'id' => $e->id,
            'employee_number' => $e->employee_number,
            'full_name' => $e->full_name,
            'photo_url' => $e->photo_url,
            'gender' => $e->gender,
            'age' => $e->date_of_birth?->age,
            'marital_status' => $e->marital_status,
            'branch' => $e->branch?->name,
            'department' => $e->department?->name,
            'position' => $e->position?->title,
            'contract_type' => $e->contract_type,
            'date_of_joining' => $e->date_of_joining?->toDateString(),
            'years_of_service' => $e->date_of_joining ? round($e->date_of_joining->diffInDays(now()) / 365.25, 1) : null,
            'status' => $e->status,
        ]);

        $count = fn ($key) => $rows->groupBy(fn ($r) => $r[$key] ?: 'Unspecified')->map->count()->sortDesc();
        $bracket = fn ($age) => match (true) {
            $age === null => 'Unknown', $age < 25 => 'Under 25', $age < 35 => '25–34', $age < 45 => '35–44', $age < 55 => '45–54', default => '55+',
        };

        return response()->json([
            'data' => $rows->values(),
            'totals' => [
                'headcount' => $rows->count(),
                'male' => $rows->where('gender', 'male')->count(),
                'female' => $rows->where('gender', 'female')->count(),
                'other' => $rows->whereNotIn('gender', ['male', 'female'])->count(),
                'average_age' => round((float) $rows->whereNotNull('age')->avg('age'), 1),
                'average_service' => round((float) $rows->whereNotNull('years_of_service')->avg('years_of_service'), 1),
                'by_department' => $count('department'),
                'by_branch' => $count('branch'),
                'by_contract' => $count('contract_type'),
                'by_age' => $rows->groupBy(fn ($r) => $bracket($r['age']))->map->count(),
            ],
        ]);
    }
}
