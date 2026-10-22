<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollRunItem;
use App\Modules\Payroll\Services\Calculation\FormulaEvaluator;
use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComponentController extends PayrollController
{
    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);
        PayrollDefaults::ensureComponents($company->id);
        $counts = PayrollEmployeeComponent::where('company_id', $company->id)->whereNull('effective_to')
            ->selectRaw('component_id, count(*) as c')->groupBy('component_id')->pluck('c', 'component_id');

        $rows = PayrollComponent::where('company_id', $company->id)
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('category')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => $c->toArray() + ['employees_count' => (int) ($counts[$c->id] ?? 0)]);

        return response()->json(['data' => $rows, 'variables' => FormulaEvaluator::VARIABLES, 'roles' => PayrollComponent::ROLES]);
    }

    private function validated(Request $request, ?PayrollComponent $component, int $companyId): array
    {
        // Sensible server-side defaults: only the name is genuinely required.
        $request->merge(array_filter([
            'code' => $request->filled('code') ? strtoupper(trim((string) $request->input('code')))
                : ($component?->code ?? $this->codeFrom((string) $request->input('name'), $companyId)),
            'category' => $request->input('category') ?: ($component?->category ?? 'earning'),
            'calculation' => $request->input('calculation') ?: ($component?->calculation ?? 'fixed'),
        ], fn ($v) => $v !== null && $v !== ''));
        foreach (['taxable' => $component?->taxable ?? true, 'pensionable' => $component?->pensionable ?? false,
            'recurring' => $component?->recurring ?? true, 'active' => $component?->active ?? true] as $flag => $default) {
            if (! $request->has($flag) || $request->input($flag) === null) {
                $request->merge([$flag => $default]);
            }
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/i',
                Rule::unique('payroll_components', 'code')->where('company_id', $companyId)->ignore($component?->id)],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(PayrollComponent::CATEGORIES)],
            'calculation' => ['required', Rule::in(PayrollComponent::CALCULATIONS)],
            'default_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'default_rate' => ['nullable', 'required_if:calculation,percent_of_basic,percent_of_gross', 'numeric', 'min:0', 'max:100'],
            'formula' => ['nullable', 'required_if:calculation,formula', 'string', 'max:500'],
            'role' => ['nullable', Rule::in(PayrollComponent::ROLES)],
            'taxable' => ['boolean'],
            'pensionable' => ['boolean'],
            'recurring' => ['boolean'],
            'gl_account' => ['nullable', 'string', 'max:30'],
            'active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        if ($data['calculation'] === 'formula' && ($err = (new FormulaEvaluator)->validate($data['formula']))) {
            abort(response()->json(['message' => $err, 'errors' => ['formula' => [$err]]], 422));
        }
        $data['code'] = strtoupper($data['code']);
        $data['sort_order'] ??= 100;
        if ($data['calculation'] === 'fixed') {
            $data['default_amount'] ??= 0;
        }

        return $data;
    }

    /** Unique component code derived from the name, e.g. "Meal allowance" → MEAL_ALLOWANCE. */
    private function codeFrom(string $name, int $companyId): ?string
    {
        $base = substr(trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($name)), '_'), 0, 26);
        if ($base === '') {
            return null;
        }
        $code = $base;
        for ($i = 2; PayrollComponent::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $component = PayrollComponent::create($this->validated($request, null, $company->id) + ['company_id' => $company->id]);
        activity('payroll')->performedOn($component)->causedBy($request->user())
            ->withProperties($component->only(['code', 'name', 'category', 'calculation', 'default_amount', 'default_rate']))->log('Pay component created');

        return response()->json(['data' => $component, 'message' => 'Component created.'], 201);
    }

    public function update(Request $request, PayrollComponent $component): JsonResponse
    {
        $before = $component->only(['code', 'name', 'category', 'calculation', 'default_amount', 'default_rate', 'taxable', 'active']);
        $component->update($this->validated($request, $component, $component->company_id));
        activity('payroll')->performedOn($component)->causedBy($request->user())
            ->withProperties(['old' => $before, 'changes' => $component->getChanges()])->log('Pay component updated');

        return response()->json(['data' => $component, 'message' => 'Component updated.']);
    }

    /** Components already used on payslips are deactivated instead of deleted, preserving history. */
    public function destroy(Request $request, PayrollComponent $component): JsonResponse
    {
        $used = PayrollRunItem::where('component_id', $component->id)->exists()
            || PayrollEmployeeComponent::where('component_id', $component->id)->exists()
            || PayrollAdjustment::where('component_id', $component->id)->exists();
        if ($used || $component->role) {
            $component->update(['active' => false]);
            activity('payroll')->performedOn($component)->causedBy($request->user())->withProperties(['code' => $component->code])->log('Pay component deactivated');

            return response()->json(['message' => 'Component is in use, so it was deactivated instead of deleted.']);
        }
        activity('payroll')->performedOn($component)->causedBy($request->user())->withProperties(['code' => $component->code, 'name' => $component->name])->log('Pay component deleted');
        $component->delete();

        return response()->json(['message' => 'Component deleted.']);
    }

    public function validateFormula(Request $request): JsonResponse
    {
        $data = $request->validate(['formula' => ['required', 'string', 'max:500'], 'basic' => ['nullable', 'numeric'], 'gross' => ['nullable', 'numeric'], 'days_worked' => ['nullable', 'numeric']]);
        $evaluator = new FormulaEvaluator;
        $error = $evaluator->validate($data['formula']);
        $sample = null;
        if (! $error) {
            try {
                $sample = round($evaluator->evaluate($data['formula'], [
                    'basic' => $data['basic'] ?? 50000, 'gross' => $data['gross'] ?? 60000, 'days_worked' => $data['days_worked'] ?? 22,
                    'working_days' => 22, 'days_in_period' => 30, 'basic_pay' => $data['basic'] ?? 50000, 'overtime_hours' => 0,
                ]), 2);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return response()->json(['valid' => ! $error, 'error' => $error, 'sample' => $sample]);
    }
}
