<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $company = \App\Models\Company::find(\App\Support\Tenancy::companyId() ?? request()->user()?->company_id);
        // What balances actually use: Annual Leave follows the company leave settings until HR edits the type.
        $types = LeaveType::with('balanceType:id,name,code')->orderBy('sort_order')->get()
            ->each(fn (LeaveType $t) => $t->setAttribute('follows_company_settings', $t->followsCompanySettings())
                ->setAttribute('effective_days_per_year', $t->entitlement($company))
                ->setAttribute('effective_monthly_accrual', $t->accrues_monthly ? $t->monthlyAccrual($company) : null));

        return response()->json(['data' => $types]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30'],
            'days_per_year' => ['nullable', 'numeric', 'min:0'],
            'opening_days' => ['nullable', 'numeric', 'min:0'],
            'counting' => ['required', 'in:working,calendar'],
            'requires_document' => ['boolean'],
            'gender' => ['nullable', 'in:male,female'],
            'accrues_monthly' => ['boolean'],
            'monthly_accrual' => ['nullable', 'numeric', 'min:0'],
            'carry_forward' => ['boolean'],
            'carry_forward_cap' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['required', 'in:full,half,unpaid'],
            'has_balance' => ['boolean'],
            'balance_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'allow_half_day' => ['boolean'],
            'allow_hours' => ['boolean'],
            'max_consecutive' => ['nullable', 'integer', 'min:1'],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['integer'],
            'active' => ['boolean'],
        ]);

        abort_if(LeaveType::where('code', $data['code'])->exists(), 422, 'A leave type with this code already exists.');

        $type = LeaveType::create($data);
        activity('leave')->performedOn($type)->log('Leave type created');

        return response()->json(['data' => $type, 'message' => 'Leave type created.'], 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'days_per_year' => ['nullable', 'numeric', 'min:0'],
            'opening_days' => ['nullable', 'numeric', 'min:0'],
            'counting' => ['required', 'in:working,calendar'],
            'requires_document' => ['boolean'],
            'gender' => ['nullable', 'in:male,female'],
            'accrues_monthly' => ['boolean'],
            'monthly_accrual' => ['nullable', 'numeric', 'min:0'],
            'carry_forward' => ['boolean'],
            'carry_forward_cap' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['required', 'in:full,half,unpaid'],
            'has_balance' => ['boolean'],
            'balance_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'allow_half_day' => ['boolean'],
            'allow_hours' => ['boolean'],
            'max_consecutive' => ['nullable', 'integer', 'min:1'],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['integer'],
            'active' => ['boolean'],
        ]);

        // An edited type keeps its own entitlement / accrual instead of following the company leave settings.
        $leaveType->update($data + ['customised' => true]);
        activity('leave')->performedOn($leaveType)->log('Leave type updated');

        return response()->json(['data' => $leaveType, 'message' => 'Leave type updated.']);
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        abort_if($leaveType->requests()->exists(), 422, 'Cannot delete a leave type that has requests.');
        $leaveType->delete();

        return response()->json(['message' => 'Leave type deleted.']);
    }
}
