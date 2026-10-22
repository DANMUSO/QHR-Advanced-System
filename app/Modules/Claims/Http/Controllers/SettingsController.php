<?php

namespace App\Modules\Claims\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Modules\Claims\Models\ClaimApprover;
use App\Modules\Claims\Models\ClaimLimit;
use App\Modules\Claims\Services\ClaimLimits;
use App\Modules\Claims\Services\ClaimWorkflow;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Claim settings (HR-FEATURES §1.9, §4.2): the company's Medical Approver and Finance Payment Person,
 * and per-employee annual / monthly limit overrides. Open to settings.manage and claims.approve (HR).
 */
class SettingsController extends Controller
{
    public function __construct(private ClaimWorkflow $workflow, private ClaimLimits $limits) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $row = ClaimApprover::forCompany($company->id)?->load(['medicalApprover.user', 'financeApprover.user']);
        $person = fn (?Employee $e) => $e ? [
            'id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number,
            'has_login' => (bool) $e->user?->is_active,
        ] : null;

        return response()->json([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'medical_approver' => $person($row?->medicalApprover),
            'finance_approver' => $person($row?->financeApprover),
            'defaults' => [
                'annual_limit' => (float) $company->setting('medical_annual_limit', Company::DEFAULT_SETTINGS['medical_annual_limit'] ?? 0),
                'monthly_limit' => (float) $company->setting('medical_monthly_limit', Company::DEFAULT_SETTINGS['medical_monthly_limit'] ?? 0),
            ],
        ]);
    }

    public function updateApprovers(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $inCompany = Rule::exists('employees', 'id')->where('company_id', $company->id)->whereNull('deleted_at');
        $data = $request->validate([
            'medical_approver_id' => ['nullable', 'integer', $inCompany],
            'finance_approver_id' => ['nullable', 'integer', $inCompany],
        ]);

        $id = fn ($key) => empty($data[$key]) ? null : (int) $data[$key];
        $this->workflow->assignApprovers($company, $id('medical_approver_id'), $id('finance_approver_id'), $request->user());

        $warnings = collect(['medical_approver_id' => 'Medical Approver', 'finance_approver_id' => 'Finance Payment Person'])
            ->filter(fn ($label, $key) => ! empty($data[$key]) && ! Employee::withoutGlobalScope('company')->find($data[$key])?->user)
            ->map(fn ($label) => "The {$label} has no login, so they cannot act until an account is created.")
            ->values();

        return response()->json(['message' => 'Claim approvers saved.', 'warnings' => $warnings]);
    }

    /** Active employees with their effective limits and this year's balance; ?overridden=1 lists only overrides. */
    public function limits(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $page = Employee::where('company_id', $company->id)->where('status', 'active')
            ->search($request->query('search'))
            ->when($request->boolean('overridden'), fn ($q) => $q->whereIn('id', ClaimLimit::withoutGlobalScope('company')->select('employee_id')))
            ->with(['department:id,name', 'company'])
            ->orderBy('first_name')->orderBy('last_name')
            ->paginate($request->integer('per_page', 25));

        $overrides = ClaimLimit::withoutGlobalScope('company')->whereIn('employee_id', $page->getCollection()->pluck('id'))->get()->keyBy('employee_id');

        return response()->json(array_merge($page->toArray(), [
            'data' => $page->getCollection()->map(function (Employee $e) use ($overrides) {
                $b = $this->limits->balanceFor($e);
                $o = $overrides->get($e->id);

                return [
                    'employee_id' => $e->id,
                    'name' => $e->full_name,
                    'employee_number' => $e->employee_number,
                    'department' => $e->department?->name,
                    'annual_override' => $o?->annual_limit !== null ? (float) $o->annual_limit : null,
                    'monthly_override' => $o?->monthly_limit !== null ? (float) $o->monthly_limit : null,
                    'notes' => $o?->notes,
                    'annual_limit' => $b['annual_limit'],
                    'monthly_limit' => $b['monthly_limit'],
                    'annual_used' => $b['annual_used'],
                    'annual_balance' => $b['annual_balance'],
                    'overridden' => $b['overridden'],
                ];
            })->values(),
        ]));
    }

    /** Set (or clear, with both limits empty) an employee's override. Empty = company default. */
    public function updateLimit(Request $request, int $employee): JsonResponse
    {
        $company = $this->company($request);
        $emp = Employee::where('company_id', $company->id)->findOrFail($employee);
        $data = $request->validate([
            'annual_limit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'monthly_limit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $annual = $data['annual_limit'] ?? null;
        $monthly = $data['monthly_limit'] ?? null;

        $limit = ClaimLimit::withoutGlobalScope('company')->firstOrNew(['employee_id' => $emp->id]);
        if ($annual === null && $monthly === null) {
            if ($limit->exists) {
                $limit->delete();
            }
            activity('claims')->performedOn($emp)->causedBy($request->user())->log('Claim limit override removed');

            return response()->json(['message' => "{$emp->full_name} now uses the company default limits."]);
        }

        $limit->forceFill([
            'company_id' => $emp->company_id,
            'annual_limit' => $annual,
            'monthly_limit' => $monthly,
            'notes' => $data['notes'] ?? null,
            'updated_by' => $request->user()->id,
        ])->save();

        return response()->json(['message' => "Claim limits saved for {$emp->full_name}.", 'data' => $this->limits->limitsFor($emp)]);
    }

    private function company(Request $request): Company
    {
        $id = Tenancy::companyIdForWrite($request->integer('company_id') ?: null) ?? $request->user()->company_id;
        abort_unless($id, 422, 'Choose a company first.');

        return Company::findOrFail($id);
    }
}
