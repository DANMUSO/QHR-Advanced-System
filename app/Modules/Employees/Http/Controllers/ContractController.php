<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function __construct(private ContractService $contracts) {}

    /** GET /employees/contracts?employee_id=&status=&search=&expiring_days= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = EmployeeContract::query()
            ->whereIn('employee_id', Employee::query()->search($request->query('search'))->select('employees.id'))
            ->with(['employee' => fn ($q) => $q->with(['department:id,name']), 'creator:id,name'])
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('contract_type'), fn ($q, $t) => $q->where('contract_type', $t))
            // Expired while the employee is still active: HR must renew or exit.
            ->when($request->boolean('action_required'), fn ($q) => $q->whereNotNull('action_required_at')->where('status', 'expired'))
            ->when($request->integer('expiring_days'), fn ($q, $d) => $q->where('status', 'active')->whereNotNull('end_date')
                ->whereBetween('end_date', [now()->toDateString(), now()->addDays($d)->toDateString()]))
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'draft' THEN 2 WHEN 'expired' THEN 3 WHEN 'superseded' THEN 4 ELSE 5 END")
            ->orderByDesc('start_date');

        $salary = $user->can('employees.view-salary');

        return response()->json($query->paginate(min($request->integer('per_page', 20) ?: 20, 100))
            ->through(fn (EmployeeContract $c) => $this->present($c, $salary)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['employee_id' => ['required', 'integer']]);
        $employee = Employee::findOrFail($request->integer('employee_id'));
        abort_unless(in_array($employee->status, ['active', 'suspended'], true), 422, 'Contracts can only be issued to current employees.');

        $contract = $this->contracts->create($employee, $this->validated($request), $request->user());

        return response()->json(['data' => $this->present($contract->load('employee'), $request->user()->can('employees.view-salary')), 'message' => 'Contract saved.'], 201);
    }

    public function update(Request $request, int $contract): JsonResponse
    {
        $c = EmployeeContract::findOrFail($contract);
        $data = $this->validated($request, true);
        if (! $request->user()->can('employees.view-salary')) {
            unset($data['gross_salary']);
        }
        $this->contracts->update($c, $data);

        return response()->json(['data' => $this->present($c->fresh('employee'), $request->user()->can('employees.view-salary')), 'message' => 'Contract updated.']);
    }

    /** GET /employees/contracts/{id}/pdf — printable contract (HR, or the employee themself). */
    public function pdf(Request $request, int $contract): Response
    {
        $c = EmployeeContract::withoutGlobalScope('company')->findOrFail($contract);
        $user = $request->user();
        abort_unless(($user->can('employees.view') && Employee::whereKey($c->employee_id)->exists()) || $user->employee?->id === $c->employee_id, 403);
        if (! $user->can('employees.view-salary') && $user->employee?->id !== $c->employee_id) {
            $c->gross_salary = null;
        }

        return $this->contracts->pdf($c)->stream(Str::slug('contract-'.$c->reference).'.pdf');
    }

    private function validated(Request $request, bool $update = false): array
    {
        return $request->validate([
            'contract_type' => [$update ? 'sometimes' : 'required', Rule::in(array_keys(EmployeeContract::TYPES))],
            'job_title' => ['nullable', 'string', 'max:150'],
            'start_date' => [$update ? 'sometimes' : 'required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date', Rule::requiredIf(fn () => in_array($request->input('contract_type'), ['fixed_term', 'internship', 'probation'], true))],
            'gross_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['nullable', Rule::in(EmployeeContract::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['end_date.required' => 'Fixed-term, probation and internship contracts need an end date.']);
    }

    private function present(EmployeeContract $c, bool $salary): array
    {
        $days = $c->end_date ? (int) now()->startOfDay()->diffInDays($c->end_date, false) : null;

        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'employee_id' => $c->employee_id,
            'employee_name' => $c->employee?->full_name,
            'employee_number' => $c->employee?->employee_number,
            'photo_url' => $c->employee?->photo_url,
            'department' => $c->employee?->department?->name,
            'contract_type' => $c->contract_type,
            'contract_type_label' => EmployeeContract::TYPES[$c->contract_type] ?? $c->contract_type,
            'job_title' => $c->job_title,
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'days_to_expiry' => $days,
            'gross_salary' => $salary && $c->gross_salary !== null ? (float) $c->gross_salary : null,
            'status' => $c->status,
            'action_required' => $c->action_required_at !== null,
            'notes' => $c->notes,
            'created_by' => $c->creator?->name,
            'created_at' => $c->created_at,
            'pdf_url' => '/api/employees/contracts/'.$c->id.'/pdf',
        ];
    }
}
