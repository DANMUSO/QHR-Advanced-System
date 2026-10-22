<?php

namespace App\Modules\Benefits\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Benefits\Models\BenefitPlan;
use App\Modules\Benefits\Models\PensionMember;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Benefits Administration (BenefitsHub.vue): benefit catalogue + pension/SACCO memberships. */
class BenefitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = BenefitPlan::query()
            ->withCount('employees as enrolled')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn (BenefitPlan $b) => $this->present($b))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->writeCompanyId($request);
        $benefit = new BenefitPlan($this->validated($request, $companyId));
        $benefit->company_id = $companyId;
        $benefit->save();

        return response()->json(['message' => 'Benefit added.', 'data' => $this->present($benefit->refresh()->loadCount('employees as enrolled'))], 201);
    }

    public function update(Request $request, BenefitPlan $benefit): JsonResponse
    {
        $benefit->update($this->validated($request, $benefit->company_id, $benefit));

        return response()->json(['message' => 'Benefit updated.', 'data' => $this->present($benefit->loadCount('employees as enrolled'))]);
    }

    public function destroy(BenefitPlan $benefit): JsonResponse
    {
        $enrolled = DB::table('benefit_employee')->where('benefit_id', $benefit->id)->count();
        abort_if($enrolled > 0, 422, "This benefit has {$enrolled} enrolled employee(s). Deactivate it instead.");
        $benefit->delete();

        return response()->json(['message' => 'Benefit removed.']);
    }

    // ---- Pension & SACCO ---------------------------------------------------------------------

    public function pension(Request $request): JsonResponse
    {
        $rows = PensionMember::with('employee:id,first_name,last_name,employee_number,photo_path')
            ->when($request->filled('scheme_type'), fn ($q) => $q->where('scheme_type', $request->string('scheme_type')))
            ->orderBy('scheme_name')->orderBy('employee_id')->get();

        return response()->json(['data' => $rows->map(fn (PensionMember $m) => $this->presentMember($m))->values()]);
    }

    public function storePension(Request $request): JsonResponse
    {
        $data = $this->validatedMember($request);
        $employee = Employee::findOrFail($data['employee_id']);

        $member = new PensionMember($data);
        $member->company_id = $employee->company_id;
        $member->save();

        return response()->json(['message' => 'Membership added.', 'data' => $this->presentMember($member->load('employee'))], 201);
    }

    public function updatePension(Request $request, PensionMember $member): JsonResponse
    {
        $member->update($this->validatedMember($request, $member));

        return response()->json(['message' => 'Membership updated.', 'data' => $this->presentMember($member->load('employee'))]);
    }

    public function destroyPension(PensionMember $member): JsonResponse
    {
        $member->delete();

        return response()->json(['message' => 'Membership removed.']);
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function writeCompanyId(Request $request): int
    {
        $id = Tenancy::companyIdForWrite($request->integer('company_id') ?: null) ?? $request->user()->company_id;
        abort_unless($id, 422, 'Select a company first.');

        return (int) $id;
    }

    private function validated(Request $request, int $companyId, ?BenefitPlan $benefit = null): array
    {
        $data = $request->validate([
            'name' => [$benefit ? 'sometimes' : 'required', 'string', 'max:120',
                Rule::unique('benefits', 'name')->where('company_id', $companyId)->ignore($benefit?->id)],
            'type' => ['sometimes', Rule::in(BenefitPlan::TYPES)],
            'value' => ['nullable', 'string', 'max:100'],
            'eligibility' => ['sometimes', Rule::in(BenefitPlan::ELIGIBILITY)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $data;
    }

    private function validatedMember(Request $request, ?PensionMember $member = null): array
    {
        $req = $member ? 'sometimes' : 'required';

        $data = $request->validate([
            'employee_id' => [$req, 'integer', 'exists:employees,id'],
            'scheme_type' => ['sometimes', Rule::in(['pension', 'sacco'])],
            'scheme_name' => [$req, 'string', 'max:150'],
            'member_number' => ['nullable', 'string', 'max:50'],
            'employee_contribution' => ['nullable', 'numeric', 'min:0'],
            'employer_contribution' => ['nullable', 'numeric', 'min:0'],
            'total_balance' => ['nullable', 'numeric', 'min:0'],
            'joined_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'exited'])],
        ]);

        // money columns are NOT NULL (default 0): a cleared field means zero
        foreach (['employee_contribution', 'employer_contribution', 'total_balance'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = 0;
            }
        }

        return $data;
    }

    private function present(BenefitPlan $b): array
    {
        return [
            'id' => $b->id,
            'company_id' => $b->company_id,
            'name' => $b->name,
            'type' => $b->type,
            'value' => $b->value,
            'eligibility' => $b->eligibility,
            'description' => $b->description,
            'is_active' => (bool) $b->is_active,
            'enrolled' => (int) ($b->enrolled ?? 0),
        ];
    }

    private function presentMember(PensionMember $m): array
    {
        return [
            'id' => $m->id,
            'employee_id' => $m->employee_id,
            'employee_name' => $m->employee?->full_name,
            'employee_number' => $m->employee?->employee_number,
            'scheme_type' => $m->scheme_type,
            'scheme_name' => $m->scheme_name,
            'member_number' => $m->member_number,
            'employee_contribution' => (float) $m->employee_contribution,
            'employer_contribution' => (float) $m->employer_contribution,
            'total_balance' => (float) $m->total_balance,
            'joined_date' => $m->joined_date?->toDateString(),
            'status' => $m->status,
        ];
    }
}
