<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Support\Tenancy;
use Illuminate\Http\Request;

abstract class PayrollController extends Controller
{
    /** Company the request acts on (super admins pick one via X-Company-Id or company_id). */
    protected function company(Request $request): Company
    {
        $id = Tenancy::companyIdForWrite($request->integer('company_id') ?: null) ?? $request->user()->company_id;
        abort_unless($id, 422, 'Select a company to work with payroll.');

        return Company::findOrFail($id);
    }

    protected function me(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }

    protected function canViewSalary(Request $request): bool
    {
        return $request->user()->can('employees.view-salary') || $request->user()->can('payroll.manage');
    }
}
