<?php

namespace App\Modules\Performance\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Request;

trait PresentsPeople
{
    protected function person(?Employee $e): ?array
    {
        if (! $e) {
            return null;
        }

        return [
            'id' => $e->id, 'name' => $e->full_name, 'photo_url' => $e->photo_url,
            'employee_number' => $e->employee_number,
            'position' => $e->relationLoaded('position') ? $e->position?->title : null,
            'department' => $e->relationLoaded('department') ? $e->department?->name : null,
        ];
    }

    protected function me(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'Your account is not linked to an employee record.');

        return $employee;
    }

    /** Company to write new records to (super admins may pass company_id or the X-Company-Id header). */
    protected function writeCompanyId(Request $request): int
    {
        $id = Tenancy::companyIdForWrite($request->integer('company_id') ?: null) ?? $request->user()->company_id;
        abort_unless($id, 422, 'Select a company first.');

        return (int) $id;
    }

    protected function actorName(User $user): string
    {
        return $user->employee?->full_name ?? $user->name;
    }

    /** Resolve an employee by id or employee number (some forms accept either). */
    protected function findEmployee(mixed $value): Employee
    {
        $employee = is_numeric($value) ? Employee::find((int) $value) : null;
        $employee ??= Employee::where('employee_number', (string) $value)->first();
        abort_unless($employee, 422, 'Employee not found.');

        return $employee;
    }
}
