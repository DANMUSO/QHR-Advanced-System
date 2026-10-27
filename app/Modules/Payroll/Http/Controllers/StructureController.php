<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollStructure;
use App\Modules\Payroll\Services\PayStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Salary structures (scales per grade) with default components that can be applied to employees. */
class StructureController extends PayrollController
{
    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $components = PayrollComponent::where('company_id', $company->id)->get()->keyBy('id');
        $rows = PayrollStructure::where('company_id', $company->id)->with('level:id,name')->withCount('profiles')->orderBy('basic_salary')->get()
            ->map(fn ($s) => $s->toArray() + [
                'grade_name' => $s->level?->name,
                'employee_count' => $s->profiles_count,
                'components_count' => count($s->components ?? []),
                'component_list' => collect($s->components ?? [])->map(fn ($c) => $c + [
                    'code' => $components[$c['component_id']]->code ?? null, 'name' => $components[$c['component_id']]->name ?? 'Removed component',
                ])->values(),
            ]);

        return response()->json(['data' => $rows]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:30'],
            'employment_level_id' => ['nullable', 'integer', Rule::exists('employment_levels', 'id')],
            'min_salary' => ['nullable', 'numeric', 'min:0'],
            'max_salary' => ['nullable', 'numeric', 'min:0', 'gte:min_salary'],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'components' => ['array'],
            'components.*.component_id' => ['required', 'integer', 'distinct', Rule::exists('payroll_components', 'id')->where('company_id', $this->company($request)->id)],
            'components.*.amount' => ['nullable', 'numeric', 'min:0'],
            'components.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['boolean'],
        ]);
        // Omitting `components` keeps the structure's current components (older clients only send name/grade/basic).
        if ($request->has('components')) {
            $data['components'] = array_values(array_map(fn ($c) => [
                'component_id' => (int) $c['component_id'],
                'amount' => isset($c['amount']) && $c['amount'] !== '' ? (float) $c['amount'] : null,
                'rate' => isset($c['rate']) && $c['rate'] !== '' ? (float) $c['rate'] : null,
            ], $data['components'] ?? []));
        } else {
            unset($data['components']);
        }

        return $data;
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $s = PayrollStructure::create($this->validated($request) + ['company_id' => $company->id]);

        return response()->json(['data' => $s, 'message' => 'Pay structure created.'], 201);
    }

    public function update(Request $request, PayrollStructure $structure): JsonResponse
    {
        $structure->update($this->validated($request));

        return response()->json(['data' => $structure, 'message' => 'Pay structure updated.']);
    }

    public function destroy(PayrollStructure $structure): JsonResponse
    {
        PayrollPayProfile::where('structure_id', $structure->id)->update(['structure_id' => null]);
        $structure->delete();

        return response()->json(['message' => 'Pay structure deleted.']);
    }

    /**
     * Link employees to the structure from a date: basic salary becomes the structure's basic (salary-history row
     * "Structure applied") and its components are assigned. Never effective before an employee's joining date.
     */
    public function apply(Request $request, PayrollStructure $structure, PayStructureService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
            'effective_from' => ['required', 'date'],
        ]);
        foreach ($data['employee_ids'] as $id) {
            PayProfileController::assertNotOwnPay($request, (int) $id);
        }
        $profiles = PayrollPayProfile::whereIn('employee_id', $data['employee_ids'])->get();
        DB::transaction(function () use ($profiles, $structure, $data, $service, $request) {
            foreach ($profiles as $p) {
                $service->apply($structure, $p, $data['effective_from'], $request->user()->id);
            }
        });
        activity('payroll')->performedOn($structure)->causedBy($request->user())
            ->withProperties(['employee_ids' => $profiles->pluck('employee_id'), 'effective_from' => $data['effective_from'], 'basic' => $structure->basic_salary])
            ->log('Pay structure applied');

        return response()->json(['message' => "Structure applied to {$profiles->count()} employee(s); basic salary set to ".number_format($structure->basic_salary, 2).'.']);
    }
}
