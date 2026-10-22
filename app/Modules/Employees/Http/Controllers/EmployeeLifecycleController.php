<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\EmploymentLevel;
use App\Models\Position;
use App\Modules\Employees\Models\EmployeeTransfer;
use App\Modules\Employees\Models\ExitRecord;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Employees\Services\LifecycleService;
use App\Modules\Employees\Support\EmployeePresenter;
use App\Modules\Employees\Support\EmployeeRules;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Exits, re-instatement, company transfers and internal moves (transfer / promotion / demotion / redeployment).
 */
class EmployeeLifecycleController extends Controller
{
    public function __construct(private LifecycleService $lifecycle) {}

    // ── Exits ────────────────────────────────────────────────────────────────

    /** GET /employees/exits — exit register (all, or one employee's). */
    public function exits(Request $request): JsonResponse
    {
        $query = ExitRecord::query()
            // Employee::query() carries the company scope, so the register only lists the user's company.
            ->whereIn('employee_id', Employee::query()->search($request->query('search'))->select('employees.id'))
            ->with(['employee' => fn ($q) => $q->withoutGlobalScope('company')->with(['department:id,name', 'position:id,title', 'successorRecord:id,previous_employee_id']), 'processor:id,name'])
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('reason'), fn ($q, $r) => $q->where('reason', $r))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('exit_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('exit_date', '<=', $d))
            ->orderByDesc('exit_date')->orderByDesc('id');

        return response()->json($query->paginate(min($request->integer('per_page', 20) ?: 20, 100))->through(fn (ExitRecord $x) => $this->presentExit($x)));
    }

    /** POST /employees/exits (register page) and POST /employees/{id}/exit (profile action). */
    public function storeExit(Request $request, ?int $employee = null): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => [$employee ? 'nullable' : 'required', 'integer'],
            'exit_date' => ['required_without:last_working_day', 'nullable', 'date'],
            'last_working_day' => ['nullable', 'date'],
            'notice_date' => ['nullable', 'date'],
            'reason' => ['required_without:exit_reason', 'nullable', Rule::in(array_keys(EmployeeExit::REASONS))],
            'exit_reason' => ['nullable', Rule::in(array_keys(EmployeeExit::REASONS))],
            'comments' => ['nullable', 'string', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'new_supervisor_id' => ['nullable', 'integer'],
            'reassignments' => ['nullable', 'array'],
            'reassignments.*.employee_id' => ['required', 'integer'],
            'reassignments.*.supervisor_id' => ['nullable', 'integer'],
        ], ['reason.required_without' => 'Select the reason for exit.', 'exit_date.required_without' => 'Enter the last working day.']);

        $e = Employee::findOrFail($employee ?? $data['employee_id']);
        $exitDate = $data['last_working_day'] ?? $data['exit_date'];
        $this->checkSupervisors($e->company_id, array_filter([$data['new_supervisor_id'] ?? null, ...collect($data['reassignments'] ?? [])->pluck('supervisor_id')->all()]), $e->id);
        if ($e->date_of_joining && $exitDate < $e->date_of_joining->toDateString()) {
            abort(422, 'The exit date cannot be before the joining date.');
        }

        $exit = $this->lifecycle->recordExit($e, [
            'exit_date' => $exitDate,
            'notice_date' => $data['notice_date'] ?? null,
            'reason' => $data['reason'] ?? $data['exit_reason'],
            'comments' => $data['comments'] ?? $data['notes'] ?? null,
            'new_supervisor_id' => $data['new_supervisor_id'] ?? null,
            'reassignments' => $data['reassignments'] ?? null,
        ], $request->user());

        return response()->json([
            'data' => $this->presentExit($exit->load('employee')),
            'message' => $exit->status === 'completed' ? "{$e->full_name} has been exited." : "Exit scheduled for {$exit->exit_date->format('j M Y')}.",
        ], 201);
    }

    /** PUT /employees/exits/{id} — clearance checklist, exit interview, comments. */
    public function updateExit(Request $request, int $exit): JsonResponse
    {
        $x = $this->findExit($exit);
        $data = $request->validate([
            'clearance' => ['nullable', 'array'],
            'clearance.*' => ['boolean'],
            'exit_interview' => ['nullable', 'string', 'max:5000'],
            'comments' => ['nullable', 'string', 'max:3000'],
            'exit_date' => ['nullable', 'date'],
            'reason' => ['nullable', Rule::in(array_keys(EmployeeExit::REASONS))],
            'assets_override_note' => ['nullable', 'string', 'max:500'],
        ]);
        if (isset($data['clearance'])) {
            if (! empty($data['clearance']['assets']) && empty($x->clearance['assets'])) {
                $x->assertAssetsClearable($data['assets_override_note'] ?? null);
                $signoffs = (array) ($x->clearance_signoffs ?? []);
                $signoffs['assets'] = ['user_id' => $request->user()->id, 'name' => $request->user()->name, 'at' => now()->toIso8601String()]
                    + ($x->outstandingAssets()->isNotEmpty() ? ['override_note' => $data['assets_override_note']] : []);
                $x->clearance_signoffs = $signoffs;
            }
            $clearance = array_intersect_key(array_merge($x->clearance ?? [], $data['clearance']), ExitRecord::CLEARANCE_ITEMS);
            $done = count(array_filter($clearance));
            $x->clearance = $clearance;
            $x->clearance_status = $done === 0 ? 'pending' : ($done === count(ExitRecord::CLEARANCE_ITEMS) ? 'cleared' : 'in_progress');
        }
        foreach (['exit_interview', 'comments'] as $f) {
            if (array_key_exists($f, $data)) {
                $x->{$f} = $data[$f];
            }
        }
        if ($x->status === 'scheduled') {
            foreach (['exit_date', 'reason'] as $f) {
                if (! empty($data[$f])) {
                    $x->{$f} = $data[$f];
                }
            }
        }
        $x->save();

        return response()->json(['data' => $this->presentExit($x->fresh('employee')), 'message' => 'Exit record updated.']);
    }

    public function cancelExit(int $exit): JsonResponse
    {
        $x = $this->findExit($exit);
        $this->lifecycle->cancelExit($x);

        return response()->json(['message' => 'Scheduled exit cancelled.']);
    }

    // ── Re-instatement ───────────────────────────────────────────────────────

    public function reinstate(Request $request, int $employee): JsonResponse
    {
        $old = Employee::findOrFail($employee);
        abort_if($old->status !== 'exited', 422, 'Only exited employees can be re-instated.');
        abort_if(Employee::withoutGlobalScopes()->where('previous_employee_id', $old->id)->exists(), 422, 'This record has already been re-instated or transferred.');
        $rules = EmployeeRules::employment($old->company_id) + ['send_activation' => ['boolean']];
        $data = Validator::make($request->all(), $rules, EmployeeRules::messages(), EmployeeRules::attributes())->validate();

        $this->checkCurrentDetailsFree($old, $old->company_id);
        $new = $this->lifecycle->reinstate($old, $data, $request->boolean('send_activation', true), $request->user());

        return response()->json([
            'data' => ['id' => $new->id, 'employee_number' => $new->employee_number],
            'message' => "{$new->full_name} has been re-instated as {$new->employee_number}.",
        ], 201);
    }

    // ── Company transfer ─────────────────────────────────────────────────────

    /** GET /employees/transfer-options?company_id= — target-company dropdowns (HR may target any group company). */
    public function transferOptions(Request $request): JsonResponse
    {
        $companyId = $request->integer('company_id');
        $data = ['companies' => Company::orderBy('name')->get(['id', 'name', 'code'])];
        if ($companyId) {
            $in = fn ($class) => $class::withoutGlobalScope('company')->where('company_id', $companyId);
            $data += [
                'branches' => $in(Branch::class)->orderBy('name')->get(['id', 'name']),
                'departments' => $in(Department::class)->orderBy('name')->get(['id', 'name']),
                'positions' => $in(Position::class)->orderBy('title')->get(['id', 'title', 'is_supervisor', 'employment_level_id']),
                'levels' => $in(EmploymentLevel::class)->orderBy('name')->get(['id', 'name', 'probation_months']),
                'supervisors' => $in(Employee::class)->where('status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'position_id'])
                    ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name]),
                'next_number' => EmployeeService::nextNumber($companyId),
            ];
        }

        return response()->json($data);
    }

    /** POST /employees/{id}/transfer — move to another company (new record there; login moves with them). */
    public function transferCompany(Request $request, int $employee): JsonResponse
    {
        $old = Employee::findOrFail($employee);
        $request->validate(['company_id' => ['required', 'integer', 'exists:companies,id', Rule::notIn([$old->company_id])]],
            ['company_id.not_in' => 'Choose a different company.']);
        $target = $request->integer('company_id');

        $rules = EmployeeRules::employment($target) + [
            'reason' => ['nullable', 'string', 'max:2000'],
            'new_supervisor_id' => ['nullable', 'integer'],
            'reassignments' => ['nullable', 'array'],
            'reassignments.*.employee_id' => ['required', 'integer'],
            'reassignments.*.supervisor_id' => ['nullable', 'integer'],
            'carry_leave_balances' => ['boolean'],
        ];
        $data = Validator::make($request->all(), $rules, EmployeeRules::messages(), EmployeeRules::attributes())->validate();
        $this->checkSupervisors($old->company_id, array_filter([$data['new_supervisor_id'] ?? null, ...collect($data['reassignments'] ?? [])->pluck('supervisor_id')->all()]), $old->id);
        $this->checkCurrentDetailsFree($old, $target);

        $new = $this->lifecycle->transferCompany($old, $target, $data, $request->user());

        return response()->json([
            'data' => ['id' => $new->id, 'company_id' => $new->company_id, 'employee_number' => $new->employee_number],
            'message' => "{$new->full_name} has been transferred to ".rtrim((string) Company::find($target)?->name, '.').'.',
        ], 201);
    }

    // ── Internal moves ───────────────────────────────────────────────────────

    /** GET /employees/transfers — transfers, promotions, demotions, redeployments and company transfers. */
    public function transfers(Request $request): JsonResponse
    {
        $query = EmployeeTransfer::query();
        if ($id = $request->integer('employee_id')) {
            $e = Employee::findOrFail($id);
            $query = EmployeeTransfer::withoutGlobalScopes()->where(fn ($q) => $q->where('employee_id', $e->id)->orWhere('new_employee_id', $e->id));
        } else {
            $query->whereHas('employee', fn ($q) => $q->withoutGlobalScope('company')->search($request->query('search')));
        }
        $query->with(['employee' => fn ($q) => $q->withoutGlobalScope('company'), 'fromDepartment:id,name', 'toDepartment:id,name',
            'fromPosition:id,title', 'toPosition:id,title', 'fromBranch:id,name', 'toBranch:id,name', 'toSupervisor:id,first_name,last_name',
            'fromCompany:id,name', 'toCompany:id,name', 'processor:id,name'])
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('effective_date')->orderByDesc('id');

        return response()->json($query->paginate(min($request->integer('per_page', 20) ?: 20, 100))->through(fn (EmployeeTransfer $t) => $this->presentTransfer($t)));
    }

    /** POST /employees/transfers — record a move; applied now, or on the effective date if in the future. */
    public function storeTransfer(Request $request): JsonResponse
    {
        $request->validate(['employee_id' => ['required', 'integer']]);
        $e = Employee::findOrFail($request->integer('employee_id'));
        $cid = $e->company_id;
        $data = $request->validate([
            'type' => ['required', Rule::in(['transfer', 'promotion', 'demotion', 'redeployment'])],
            'to_branch_id' => ['nullable', EmployeeRules::inCompany('branches', $cid)],
            'to_department_id' => ['nullable', EmployeeRules::inCompany('departments', $cid)],
            'to_position_id' => ['nullable', EmployeeRules::inCompany('positions', $cid)],
            'to_level_id' => ['nullable', EmployeeRules::inCompany('employment_levels', $cid)],
            'to_supervisor_id' => ['nullable', 'integer', Rule::notIn([$e->id])],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ], ['to_supervisor_id.not_in' => 'An employee cannot supervise themself.']);

        $this->checkSupervisors($cid, array_filter([$data['to_supervisor_id'] ?? null]), $e->id);
        $changes = collect(['to_branch_id' => 'branch_id', 'to_department_id' => 'department_id', 'to_position_id' => 'position_id',
            'to_level_id' => 'employment_level_id', 'to_supervisor_id' => 'primary_supervisor_id'])
            ->filter(fn ($col, $key) => ! empty($data[$key]) && (int) $data[$key] !== (int) $e->{$col});
        abort_if($changes->isEmpty(), 422, 'Choose at least one new branch, department, position, level or supervisor.');
        if (in_array($data['type'], ['promotion', 'demotion'], true) && empty($data['to_position_id']) && empty($data['to_level_id'])) {
            abort(422, 'A promotion or demotion needs a new position or level.');
        }

        $move = $this->lifecycle->recordMove($e, $data, $request->user());

        return response()->json([
            'data' => $this->presentTransfer($move->load(['employee', 'toDepartment', 'toPosition', 'fromDepartment', 'fromPosition'])),
            'message' => $move->status === 'completed' ? 'Move recorded and applied.' : 'Move scheduled for '.$move->effective_date->format('j M Y').'.',
        ], 201);
    }

    public function cancelTransfer(int $transfer): JsonResponse
    {
        $t = EmployeeTransfer::findOrFail($transfer);
        $this->lifecycle->cancelMove($t);

        return response()->json(['message' => 'Scheduled move cancelled.']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function findExit(int $id): ExitRecord
    {
        $x = ExitRecord::findOrFail($id);
        Employee::findOrFail($x->employee_id); // company scope check

        return $x;
    }

    /** Supervisors chosen for reassignment must be active staff of the company (and not the leaver). */
    private function checkSupervisors(int $companyId, array $ids, int $exclude): void
    {
        $ids = array_unique(array_map('intval', $ids));
        if (! $ids) {
            return;
        }
        abort_if(in_array($exclude, $ids, true), 422, 'Pick someone other than the employee themself as the new supervisor.');
        $valid = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->whereIn('id', $ids)->count();
        abort_if($valid !== count($ids), 422, 'The selected supervisor must be an active employee of the same company.');
    }

    /** Email / phone / national ID / KRA PIN must not already belong to a current employee of the target company. */
    private function checkCurrentDetailsFree(Employee $e, int $companyId): void
    {
        $data = $e->only(['email', 'phone', 'national_id', 'kra_pin']);
        Validator::make($data, collect($data)->filter()->map(fn ($v, $col) => [EmployeeRules::uniqueCurrent($col, $companyId, $e->id)])->all(), [
            'unique' => 'The :attribute of this employee is already used by a current employee in that company.',
        ])->validate();
    }

    private function presentExit(ExitRecord $x): array
    {
        $e = $x->employee;

        return [
            'id' => $x->id,
            'employee_id' => $x->employee_id,
            'employee_name' => $e?->full_name,
            'employee_number' => $e?->employee_number,
            'photo_url' => $e?->photo_url,
            'department' => $e?->department?->name,
            'position' => $e?->position?->title,
            'employee_status' => $e?->status,
            'exit_reason' => $x->reason,
            'exit_reason_label' => EmployeeExit::REASONS[$x->reason] ?? $x->reason,
            'notice_date' => $x->notice_date?->toDateString(),
            'last_working_day' => $x->exit_date?->toDateString(),
            'exit_date' => $x->exit_date?->toDateString(),
            'status' => $x->status,
            'clearance_status' => $x->clearance_status,
            'clearance' => collect(ExitRecord::CLEARANCE_ITEMS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'done' => (bool) ($x->clearance[$key] ?? false),
                'override_note' => $x->clearance_signoffs[$key]['override_note'] ?? null])->values(),
            'outstanding_assets' => $x->assetsPayload(),
            // Payroll deep link: prepares the leaver's final dues run.
            'final_dues_url' => '/payroll?final_dues='.$x->employee_id,
            'exit_interview' => $x->exit_interview,
            'comments' => $x->comments,
            'processed_by' => $x->processor?->name,
            'completed_at' => $x->completed_at,
            'reinstated' => (bool) $e?->successorRecord,
            'can_reinstate' => $e?->status === 'exited' && ! $e?->successorRecord && $x->status === 'completed',
            'created_at' => $x->created_at,
        ];
    }

    private function presentTransfer(EmployeeTransfer $t): array
    {
        return [
            'id' => $t->id,
            'employee_id' => $t->employee_id,
            'new_employee_id' => $t->new_employee_id,
            'employee_name' => $t->employee?->full_name,
            'employee_number' => $t->employee?->employee_number,
            'photo_url' => $t->employee?->photo_url,
            'type' => $t->type,
            'type_label' => EmployeeTransfer::TYPES[$t->type] ?? $t->type,
            'from_company' => $t->fromCompany?->name,
            'to_company' => $t->toCompany?->name,
            'from_branch' => $t->fromBranch?->name,
            'to_branch' => $t->toBranch?->name,
            'from_department' => $t->fromDepartment?->name,
            'to_department' => $t->toDepartment?->name,
            'from_position' => $t->fromPosition?->title,
            'to_position' => $t->toPosition?->title,
            'to_supervisor' => $t->toSupervisor?->full_name,
            'effective_date' => $t->effective_date?->toDateString(),
            'reason' => $t->reason,
            'status' => $t->status,
            'applied_at' => $t->applied_at,
            'processed_by' => $t->processor?->name,
        ];
    }
}
