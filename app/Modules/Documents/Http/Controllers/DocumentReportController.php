<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HR views across personal files: per-employee file summary and the document-expiry register. */
class DocumentReportController extends Controller
{
    /** GET /documents/employees?search=&department_id= — every current employee's file: counts, missing required, last update. */
    public function employees(Request $request): JsonResponse
    {
        $employees = Employee::query()->whereIn('status', ['active', 'suspended'])->search($request->query('search'))
            ->when($request->integer('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->with('department:id,name')->orderBy('first_name')->orderBy('last_name')->limit(2000)->get();

        $stats = EmployeeDocument::query()->whereIn('employee_id', $employees->pluck('id'))
            ->selectRaw('employee_id, COUNT(*) as total, MAX(updated_at) as last_updated, SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired')
            ->groupBy('employee_id')->get()->keyBy('employee_id');
        $have = EmployeeDocument::query()->whereIn('employee_id', $employees->pluck('id'))->whereNotNull('document_type_id')
            ->get(['employee_id', 'document_type_id'])->groupBy('employee_id')->map(fn ($g) => $g->pluck('document_type_id')->unique());
        $required = DocumentType::query()->where('is_required', true)->where('is_active', true)->get(['id', 'company_id', 'name']);

        return response()->json(['data' => $employees->map(function (Employee $e) use ($stats, $have, $required) {
            $needed = $required->where('company_id', $e->company_id);
            $missing = $needed->whereNotIn('id', ($have[$e->id] ?? collect())->all());
            $s = $stats[$e->id] ?? null;

            return [
                'id' => $e->id,
                'employee_id' => $e->id,
                'employee_name' => $e->full_name,
                'employee_number' => $e->employee_number,
                'photo_url' => $e->photo_url,
                'department' => $e->department?->name,
                'documents_count' => (int) ($s->total ?? 0),
                'expired_count' => (int) ($s->expired ?? 0),
                'missing_required' => $missing->count(),
                'missing' => $missing->pluck('name')->values(),
                'required_total' => $needed->count(),
                'last_updated' => $s?->last_updated ? substr((string) $s->last_updated, 0, 10) : null,
            ];
        })->values()]);
    }

    /**
     * GET /documents/expiry?days=30&employee_id=&include_expired=1
     * Documents expiring within `days` (and already expired), or every dated document of one employee.
     */
    public function expiry(Request $request): JsonResponse
    {
        $days = min(max($request->integer('days', 30), 1), 365);
        $employeeId = $request->integer('employee_id');

        $docs = EmployeeDocument::query()->whereNotNull('expiry_date')
            ->whereIn('employee_id', Employee::query()->whereIn('status', ['active', 'suspended'])->select('employees.id'))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId),
                fn ($q) => $q->where('expiry_date', '<=', today()->addDays($days)->toDateString())
                    ->when(! $request->boolean('include_expired', true), fn ($w) => $w->where('expiry_date', '>=', today()->toDateString())))
            ->with(['type:id,name', 'employee:id,first_name,last_name,employee_number,department_id,photo_path', 'employee.department:id,name'])
            ->orderBy('expiry_date')->limit(1000)->get();

        return response()->json(['data' => $docs->map(function (EmployeeDocument $d) {
            $left = (int) today()->diffInDays($d->expiry_date, false);

            return [
                'id' => $d->id,
                'employee_id' => $d->employee_id,
                'employee_name' => $d->employee?->full_name,
                'employee_number' => $d->employee?->employee_number,
                'photo_url' => $d->employee?->photo_url,
                'department' => $d->employee?->department?->name,
                'title' => $d->title,
                'document_type' => $d->type?->name ?? $d->title,
                'document_number' => $d->title,
                'issue_date' => $d->issue_date?->toDateString(),
                'expiry_date' => $d->expiry_date?->toDateString(),
                'days_remaining' => $left,
                'status' => $left < 0 ? 'expired' : ($left <= 7 ? 'critical' : ($left <= 30 ? 'expiring' : 'valid')),
            ];
        })->values()]);
    }
}
