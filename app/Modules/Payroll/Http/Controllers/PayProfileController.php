<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollLoan;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Models\PayrollStructure;
use App\Modules\Payroll\Services\PayslipService;
use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayProfileController extends PayrollController
{
    /** Employees with their pay profile summary. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canViewSalary($request), 403);
        $search = trim((string) $request->query('search'));

        $page = Employee::query()->with(['department:id,name', 'position:id,title', 'branch:id,name'])
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->query('status', 'active') !== 'all', fn ($q) => $q->where('status', $request->query('status', 'active')))
            ->when($request->query('profile') === 'missing', fn ($q) => $q->whereNotIn('id', PayrollPayProfile::withoutGlobalScopes()->select('employee_id')))
            ->when($request->query('profile') === 'set', fn ($q) => $q->whereIn('id', PayrollPayProfile::withoutGlobalScopes()->select('employee_id')))
            ->orderBy('first_name')->orderBy('last_name')
            ->paginate(min($request->integer('per_page', 25) ?: 25, 200));

        $profiles = PayrollPayProfile::withoutGlobalScopes()->whereIn('employee_id', $page->getCollection()->pluck('id'))->get()->keyBy('employee_id');

        return response()->json($page->through(function (Employee $e) use ($profiles) {
            $p = $profiles->get($e->id);

            return [
                'id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number, 'photo_url' => $e->photo_url,
                'department' => $e->department?->name, 'position' => $e->position?->title, 'branch' => $e->branch?->name, 'status' => $e->status,
                'kra_pin' => $e->kra_pin,
                'has_profile' => (bool) $p,
                'basic_salary' => $p?->basic_salary,
                'payment_method' => $p?->payment_method,
                'payment_method_label' => $p ? (PayrollPayProfile::PAYMENT_METHODS[$p->payment_method] ?? $p->payment_method) : null,
                'payment_ready' => $p ? $p->isPaymentReady() : false,
                'on_hold' => $p?->on_hold ?? false,
            ];
        }));
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->canViewSalary($request), 403);
        PayrollDefaults::ensureComponents($employee->company_id);
        $profile = PayrollPayProfile::where('employee_id', $employee->id)->first();
        $employee->load(['department:id,name', 'position:id,title', 'branch:id,name']);

        return response()->json([
            'employee' => [
                'id' => $employee->id, 'name' => $employee->full_name, 'employee_number' => $employee->employee_number,
                'photo_url' => $employee->photo_url, 'department' => $employee->department?->name, 'position' => $employee->position?->title,
                'branch' => $employee->branch?->name, 'status' => $employee->status, 'kra_pin' => $employee->kra_pin,
                'nssf_number' => $employee->nssf_number, 'shif_number' => $employee->shif_number, 'helb_number' => $employee->helb_number,
                'date_of_joining' => $employee->date_of_joining?->toDateString(), 'phone' => $employee->phone,
                'previous_employee_id' => $employee->previous_employee_id,
            ],
            'profile' => $profile ? $this->presentProfile($profile) : null,
            'can_edit' => $request->user()->can('payroll.manage'),
            'can_copy_previous' => ! $profile && $employee->previous_employee_id
                && PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $employee->previous_employee_id)->exists(),
            'salary_history' => PayrollSalaryHistory::with(['approver:id,name', 'creator:id,name'])->where('employee_id', $employee->id)
                ->orderByDesc('effective_date')->orderByDesc('id')->get()
                ->map(fn ($h) => [
                    'id' => $h->id, 'basic_salary' => $h->basic_salary, 'previous_salary' => $h->previous_salary,
                    'change_pct' => $h->previous_salary ? round(($h->basic_salary - $h->previous_salary) / $h->previous_salary * 100, 1) : null,
                    'effective_date' => $h->effective_date?->toDateString(), 'reason' => $h->reason,
                    'reason_label' => PayrollSalaryHistory::REASONS[$h->reason] ?? ucfirst(str_replace('_', ' ', (string) $h->reason)), 'notes' => $h->notes,
                    'approved_by' => $h->approver?->name, 'created_by' => $h->creator?->name, 'created_at' => $h->created_at,
                ]),
            'components' => PayrollEmployeeComponent::with('component')->where('employee_id', $employee->id)
                ->orderByRaw('effective_to is not null')->orderByDesc('effective_from')->get()
                ->map(fn ($a) => $this->presentAssignment($a)),
            'available_components' => PayrollComponent::where('company_id', $employee->company_id)->where('active', true)
                ->where(fn ($q) => $q->whereNull('role')->orWhereNotIn('role', ['loan', 'advance', 'overtime', 'leave_encashment', 'notice_pay']))
                ->orderBy('sort_order')->get(['id', 'code', 'name', 'category', 'calculation', 'default_amount', 'default_rate', 'formula', 'role']),
            'structures' => PayrollStructure::where('active', true)->orderBy('name')->get(['id', 'name', 'basic_salary', 'min_salary', 'max_salary']),
            'loans' => PayrollLoan::where('employee_id', $employee->id)->latest()->get()
                ->map(fn ($l) => ['id' => $l->id, 'reference' => $l->reference, 'principal' => $l->principal, 'balance' => $l->balance, 'status' => $l->status]),
            'recent_payslips' => PayrollRunEmployee::with('run:id,title,period,status,type')->where('employee_id', $employee->id)
                ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
                ->latest('id')->limit(6)->get()
                ->map(fn ($l) => ['id' => $l->id, 'run_id' => $l->payroll_run_id, 'title' => $l->run?->title, 'period' => $l->run?->period?->format('Y-m'),
                    'gross_pay' => $l->gross_pay, 'net_pay' => $l->net_pay]),
        ]);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(['bank', 'mpesa', 'cash'])],
            'bank_name' => ['nullable', 'required_if:payment_method,bank', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'bank_branch' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:20'],
            'account_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'required_if:payment_method,bank', 'string', 'max:40', 'regex:/^[0-9A-Za-z\- ]+$/'],
            'mpesa_phone' => ['nullable', 'required_if:payment_method,mpesa', 'string', 'regex:/^(\+?254|0)(7|1)\d{8}$/'],
            'disability_exempt' => ['boolean'],
            'disability_certificate' => ['nullable', 'string', 'max:60'],
            'pension_employee_percent' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'pension_employer_percent' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'structure_id' => ['nullable', 'integer', Rule::exists('payroll_structures', 'id')],
            'on_hold' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // initial salary when creating a profile
            'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'effective_date' => ['nullable', 'date'],
        ], ['mpesa_phone.regex' => 'Enter a Kenyan mobile number, e.g. 0712345678.']);

        $profile = PayrollPayProfile::where('employee_id', $employee->id)->first();
        $creating = ! $profile;
        $basic = $data['basic_salary'] ?? null;
        $effective = $data['effective_date'] ?? ($employee->date_of_joining?->toDateString() ?? now()->toDateString());
        unset($data['basic_salary'], $data['effective_date']);
        $data['pension_employee_percent'] = $data['pension_employee_percent'] ?? 0;
        $data['pension_employer_percent'] = $data['pension_employer_percent'] ?? 0;

        DB::transaction(function () use (&$profile, $creating, $employee, $data, $basic, $effective, $request) {
            if ($creating) {
                abort_if($basic === null, 422, 'Enter the basic salary for the new pay profile.');
                $profile = PayrollPayProfile::create($data + ['company_id' => $employee->company_id, 'employee_id' => $employee->id, 'basic_salary' => $basic]);
                PayrollSalaryHistory::create([
                    'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'previous_salary' => null,
                    'basic_salary' => $basic, 'effective_date' => $effective, 'reason' => 'initial',
                    'approved_by' => $request->user()->id, 'created_by' => $request->user()->id,
                ]);
            } else {
                $profile->update($data);
            }
        });

        return response()->json(['data' => $this->presentProfile($profile->fresh()), 'message' => $creating ? 'Pay profile created.' : 'Pay profile updated.']);
    }

    public function copyPrevious(Request $request, Employee $employee): JsonResponse
    {
        abort_if(PayrollPayProfile::where('employee_id', $employee->id)->exists(), 422, 'This employee already has a pay profile.');
        $old = PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $employee->previous_employee_id)->firstOrFail();
        $profile = PayrollPayProfile::create(collect($old->getAttributes())->except(['id', 'company_id', 'employee_id', 'structure_id', 'created_at', 'updated_at'])->all()
            + ['company_id' => $employee->company_id, 'employee_id' => $employee->id]);
        PayrollSalaryHistory::create([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'previous_salary' => null, 'basic_salary' => $old->basic_salary,
            'effective_date' => $employee->date_of_joining?->toDateString() ?? now()->toDateString(), 'reason' => 'initial',
            'notes' => 'Carried over from previous employment record', 'approved_by' => $request->user()->id, 'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->presentProfile($profile), 'message' => 'Pay profile copied from the previous record.']);
    }

    /** Versioned salary change; the profile's current basic follows the latest effective change. */
    public function changeSalary(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', Rule::in(array_keys(PayrollSalaryHistory::REASONS))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $profile = PayrollPayProfile::where('employee_id', $employee->id)->first();
        abort_unless($profile, 422, 'Create the pay profile first.');
        $this->applySalaryChange($employee, $profile, $data, $request->user()->id);

        return response()->json(['message' => 'Salary change recorded.', 'data' => $this->presentProfile($profile->fresh())]);
    }

    private function applySalaryChange(Employee $employee, PayrollPayProfile $profile, array $data, int $userId): void
    {
        DB::transaction(function () use ($employee, $profile, $data, $userId) {
            $prev = PayrollSalaryHistory::where('employee_id', $employee->id)->whereDate('effective_date', '<=', $data['effective_date'])
                ->orderByDesc('effective_date')->orderByDesc('id')->value('basic_salary') ?? $profile->basic_salary;
            PayrollSalaryHistory::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'previous_salary' => $prev,
                'basic_salary' => $data['basic_salary'], 'effective_date' => $data['effective_date'], 'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null, 'approved_by' => $userId, 'created_by' => $userId,
            ]);
            $current = PayrollSalaryHistory::where('employee_id', $employee->id)->whereDate('effective_date', '<=', now()->toDateString())
                ->orderByDesc('effective_date')->orderByDesc('id')->value('basic_salary');
            $profile->update(['basic_salary' => $current ?? $profile->basic_salary]);
        });
    }

    public function storeComponent(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validateAssignment($request, $employee);
        $a = PayrollEmployeeComponent::create($data + ['company_id' => $employee->company_id, 'employee_id' => $employee->id]);

        return response()->json(['data' => $this->presentAssignment($a->load('component')), 'message' => 'Component assigned.'], 201);
    }

    public function updateComponent(Request $request, Employee $employee, PayrollEmployeeComponent $assignment): JsonResponse
    {
        abort_unless((int) $assignment->employee_id === $employee->id, 404);
        $assignment->update($this->validateAssignment($request, $employee));

        return response()->json(['data' => $this->presentAssignment($assignment->load('component')), 'message' => 'Component updated.']);
    }

    /** Ends the assignment (keeps history) unless it never took effect. */
    public function destroyComponent(Request $request, Employee $employee, PayrollEmployeeComponent $assignment): JsonResponse
    {
        abort_unless((int) $assignment->employee_id === $employee->id, 404);
        if ($assignment->effective_from->isFuture()) {
            $assignment->delete();
        } else {
            $assignment->update(['effective_to' => $request->input('effective_to', now()->subMonthNoOverflow()->endOfMonth()->toDateString())]);
        }

        return response()->json(['message' => 'Component removed.']);
    }

    private function validateAssignment(Request $request, Employee $employee): array
    {
        $data = $request->validate([
            'component_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $component = PayrollComponent::where('company_id', $employee->company_id)->findOrFail($data['component_id']);
        if ($component->calculation === 'fixed' && $data['amount'] === null && $component->default_amount === null) {
            abort(422, 'Enter an amount for this component.');
        }
        if (in_array($component->calculation, ['percent_of_basic', 'percent_of_gross'], true) && $data['rate'] === null && $component->default_rate === null) {
            abort(422, 'Enter a rate (%) for this component.');
        }

        return $data;
    }

    /** CSV: employee_number, basic, effective_date, reason. dry_run=1 validates without saving. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'], 'dry_run' => ['boolean']]);
        $company = $this->company($request);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        foreach (['employee_number', 'basic', 'effective_date'] as $required) {
            abort_unless(in_array($required, $header, true), 422, "Missing column \"{$required}\". Expected: employee_number, basic, effective_date, reason.");
        }
        $rows = [];
        $line = 1;
        while (($cols = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($cols, 0, count($header)), count($header), null));
            $errors = [];
            $employee = Employee::withoutGlobalScope('company')->where('company_id', $company->id)->where('employee_number', trim((string) $r['employee_number']))->first();
            $profile = $employee ? PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $employee->id)->first() : null;
            $basic = str_replace([',', ' '], '', (string) $r['basic']);
            $reason = strtolower(trim((string) ($r['reason'] ?? 'adjustment'))) ?: 'adjustment';
            $date = strtotime((string) $r['effective_date']) ? date('Y-m-d', strtotime((string) $r['effective_date'])) : null;
            if (! $employee) {
                $errors[] = 'Unknown employee number';
            } elseif (! $profile) {
                $errors[] = 'Employee has no pay profile';
            }
            if (! is_numeric($basic) || $basic < 0) {
                $errors[] = 'Invalid basic salary';
            }
            if (! $date) {
                $errors[] = 'Invalid effective date';
            }
            if (! array_key_exists($reason, PayrollSalaryHistory::REASONS)) {
                $errors[] = 'Reason must be promotion, increment, adjustment or initial';
            }
            $rows[] = ['line' => $line, 'employee_number' => $r['employee_number'], 'employee_name' => $employee?->full_name,
                'current_basic' => $profile?->basic_salary, 'basic' => is_numeric($basic) ? (float) $basic : null,
                'effective_date' => $date, 'reason' => $reason, 'errors' => $errors,
                '_employee' => $employee, '_profile' => $profile];
        }
        fclose($handle);

        $valid = collect($rows)->filter(fn ($r) => ! $r['errors']);
        $applied = 0;
        if (! $request->boolean('dry_run') && $valid->count() === count($rows)) {
            foreach ($valid as $r) {
                $this->applySalaryChange($r['_employee'], $r['_profile'], ['basic_salary' => $r['basic'], 'effective_date' => $r['effective_date'], 'reason' => $r['reason'], 'notes' => 'Bulk import'], $request->user()->id);
                $applied++;
            }
            activity('payroll')->causedBy($request->user())->withProperties(['rows' => $applied])->log('Bulk salary import');
        }

        return response()->json([
            'rows' => collect($rows)->map(fn ($r) => collect($r)->except(['_employee', '_profile']))->values(),
            'valid' => $valid->count(), 'invalid' => count($rows) - $valid->count(), 'applied' => $applied,
            'message' => $applied ? "{$applied} salary change(s) applied." : (count($rows) - $valid->count() ? 'Fix the errors below and re-upload; nothing was saved.' : 'File validated. Confirm to apply.'),
        ]);
    }

    private function presentProfile(PayrollPayProfile $p): array
    {
        return [
            'id' => $p->id, 'employee_id' => $p->employee_id, 'structure_id' => $p->structure_id, 'basic_salary' => $p->basic_salary,
            'payment_method' => $p->payment_method, 'bank_name' => $p->bank_name, 'bank_code' => $p->bank_code, 'bank_branch' => $p->bank_branch,
            'branch_code' => $p->branch_code, 'account_name' => $p->account_name, 'account_number' => $p->account_number,
            'account_number_masked' => PayslipService::mask($p->account_number), 'mpesa_phone' => $p->mpesa_phone,
            'mpesa_phone_masked' => PayslipService::mask($p->mpesa_phone, 3), 'disability_exempt' => $p->disability_exempt,
            'disability_certificate' => $p->disability_certificate, 'pension_employee_percent' => $p->pension_employee_percent,
            'pension_employer_percent' => $p->pension_employer_percent, 'on_hold' => $p->on_hold, 'notes' => $p->notes,
            'payment_method_label' => PayrollPayProfile::PAYMENT_METHODS[$p->payment_method] ?? $p->payment_method,
            'payment_ready' => $p->isPaymentReady(), 'readiness_issues' => $p->readinessIssues(), 'updated_at' => $p->updated_at,
        ];
    }

    private function presentAssignment(PayrollEmployeeComponent $a): array
    {
        $c = $a->component;

        return [
            'id' => $a->id, 'component_id' => $a->component_id,
            'component' => $c ? ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'category' => $c->category,
                'category_label' => PayrollComponent::CATEGORY_LABELS[$c->category] ?? $c->category, 'calculation' => $c->calculation,
                'default_amount' => $c->default_amount, 'default_rate' => $c->default_rate, 'formula' => $c->formula] : null,
            'amount' => $a->amount, 'rate' => $a->rate,
            'effective_from' => $a->effective_from?->toDateString(), 'effective_to' => $a->effective_to?->toDateString(),
            'active' => ! $a->effective_to || $a->effective_to->gte(now()->startOfDay()),
            'notes' => $a->notes,
        ];
    }
}
