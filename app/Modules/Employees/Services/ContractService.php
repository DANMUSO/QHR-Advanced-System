<?php

namespace App\Modules\Employees\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Models\EmployeeContract;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Employment contracts: one active contract per employee; a new active contract supersedes the previous one
 * and keeps employees.contract_type / contract_end_date in sync.
 */
class ContractService
{
    public function create(Employee $employee, array $data, ?User $by): EmployeeContract
    {
        return DB::transaction(function () use ($employee, $data, $by) {
            $status = $data['status'] ?? 'active';
            if ($status === 'active') {
                EmployeeContract::withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', 'active')
                    ->get()->each(fn ($c) => $c->update(['status' => 'superseded']));
            }

            $contract = new EmployeeContract([
                'employee_id' => $employee->id,
                'reference' => self::nextReference($employee->company_id),
                'contract_type' => $data['contract_type'],
                'job_title' => $data['job_title'] ?? $employee->position()->withoutGlobalScope('company')->value('title'),
                'start_date' => $data['start_date'],
                'end_date' => $data['contract_type'] === 'permanent' ? null : ($data['end_date'] ?? null),
                'gross_salary' => $data['gross_salary'] ?? null,
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by?->id,
            ]);
            $contract->forceFill(['company_id' => $employee->company_id])->save();

            if ($status === 'active') {
                $this->syncEmployee($employee, $contract);
                ContractAlerts::clearFor($employee->id); // renewed: the expired contract no longer needs HR action
            }

            return $contract;
        });
    }

    public function update(EmployeeContract $contract, array $data): EmployeeContract
    {
        return DB::transaction(function () use ($contract, $data) {
            $contract->fill(array_intersect_key($data, array_flip(['contract_type', 'job_title', 'start_date', 'end_date', 'gross_salary', 'status', 'notes'])));
            if ($contract->contract_type === 'permanent') {
                $contract->end_date = null;
            }
            $activated = $contract->isDirty('status') && $contract->status === 'active';
            $contract->save();

            if ($activated) {
                ContractAlerts::clearFor($contract->employee_id);
                EmployeeContract::withoutGlobalScopes()->where('employee_id', $contract->employee_id)->where('status', 'active')
                    ->whereKeyNot($contract->id)->get()->each(fn ($c) => $c->update(['status' => 'superseded']));
            }
            if ($contract->status === 'active') {
                $this->syncEmployee($contract->employee()->withoutGlobalScope('company')->first(), $contract);
            }

            return $contract;
        });
    }

    /** Opening contract for a new employment record (onboarding, re-instatement, transfer). */
    public function fromEmployment(Employee $employee, ?User $by): EmployeeContract
    {
        $type = match ($employee->contract_type) {
            'contract' => 'fixed_term', 'intern' => 'internship', 'casual' => 'casual', default => 'permanent',
        };

        return $this->create($employee, [
            'contract_type' => $type,
            'start_date' => ($employee->date_of_joining ?? now())->toDateString(),
            'end_date' => $employee->contract_end_date?->toDateString(),
        ], $by);
    }

    private function syncEmployee(Employee $employee, EmployeeContract $contract): void
    {
        $employee->forceFill([
            'contract_type' => EmployeeContract::EMPLOYEE_TYPE[$contract->contract_type] ?? $employee->contract_type,
            'contract_end_date' => $contract->end_date,
        ]);
        if ($employee->isDirty()) {
            $employee->save();
        }
    }

    public static function nextReference(int $companyId): string
    {
        $prefix = 'CT-'.now()->format('Y').'-';
        $last = EmployeeContract::withoutGlobalScopes()->withTrashed()->where('company_id', $companyId)
            ->where('reference', 'like', $prefix.'%')->orderByDesc('id')->value('reference');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function pdf(EmployeeContract $contract)
    {
        $employee = $contract->employee()->withoutGlobalScope('company')
            ->with(['position' => fn ($q) => $q->withoutGlobalScope('company'), 'department' => fn ($q) => $q->withoutGlobalScope('company'),
                'branch' => fn ($q) => $q->withoutGlobalScope('company')])->first();
        $company = Company::withTrashed()->find($contract->company_id);

        return Pdf::loadView('employees::contract', [
            'contract' => $contract, 'employee' => $employee, 'company' => $company,
            'typeLabel' => EmployeeContract::TYPES[$contract->contract_type] ?? $contract->contract_type,
            'currency' => $company?->setting('currency') ?? 'KES',
        ])->setPaper('a4');
    }
}
