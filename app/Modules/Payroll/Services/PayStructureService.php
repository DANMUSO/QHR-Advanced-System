<?php

namespace App\Modules\Payroll\Services;

use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Models\PayrollStructure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applying a pay structure (scale per grade / employment level) to an employee: links the profile, sets the basic
 * salary to the structure's basic (a versioned salary-history row) and assigns the structure's components.
 * Nothing takes effect before the employee's joining date.
 */
class PayStructureService
{
    /** The active structure for the employee's employment level (grade), if exactly one matches. */
    public function matchFor(Employee $employee): ?PayrollStructure
    {
        if (! $employee->employment_level_id) {
            return null;
        }
        $matches = PayrollStructure::withoutGlobalScopes()->where('company_id', $employee->company_id)
            ->where('employment_level_id', $employee->employment_level_id)->where('active', true)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Effective date clamped to the joining date (a structure never applies before employment starts). */
    public static function effectiveFor(Employee $employee, string $date): string
    {
        $joined = $employee->date_of_joining?->toDateString();

        return $joined && $joined > $date ? $joined : $date;
    }

    /** Salary-history reason is 'structure' ("Structure applied"), or 'initial' when it is the employee's first salary. */
    public function apply(PayrollStructure $structure, PayrollPayProfile $profile, string $effectiveFrom, ?int $userId = null, bool $setBasic = true): void
    {
        $employee = Employee::withoutGlobalScopes()->findOrFail($profile->employee_id);
        $from = self::effectiveFor($employee, CarbonImmutable::parse($effectiveFrom)->toDateString());

        DB::transaction(function () use ($structure, $profile, $employee, $from, $userId, $setBasic) {
            $profile->update(['structure_id' => $structure->id]);

            if ($setBasic && $structure->basic_salary > 0) {
                $history = PayrollSalaryHistory::withoutGlobalScopes()->where('employee_id', $employee->id);
                $first = ! (clone $history)->where('basic_salary', '>', 0)->exists();
                $prev = (clone $history)->whereDate('effective_date', '<=', $from)->orderByDesc('effective_date')->orderByDesc('id')->value('basic_salary')
                    ?? $profile->basic_salary;
                if ($first || abs((float) $prev - $structure->basic_salary) >= 0.01) {
                    PayrollSalaryHistory::withoutGlobalScopes()->create([
                        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                        'previous_salary' => $first ? null : $prev, 'basic_salary' => $structure->basic_salary,
                        'effective_date' => $from, 'reason' => $first ? 'initial' : 'structure',
                        'notes' => "Structure applied: {$structure->name}", 'approved_by' => $userId, 'created_by' => $userId,
                    ]);
                }
                // Profile basic = salary in force today (or the first one, for a hire who starts later).
                $rows = PayrollSalaryHistory::withoutGlobalScopes()->where('employee_id', $employee->id)->orderByDesc('effective_date')->orderByDesc('id');
                $current = (clone $rows)->whereDate('effective_date', '<=', now()->toDateString())->value('basic_salary')
                    ?? $rows->reorder()->orderBy('effective_date')->orderBy('id')->value('basic_salary');
                $profile->update(['basic_salary' => $current ?? $profile->basic_salary]);
            }

            foreach ($structure->components ?? [] as $c) {
                PayrollEmployeeComponent::withoutGlobalScopes()->where('employee_id', $employee->id)->where('component_id', $c['component_id'])
                    ->whereNull('effective_to')->whereDate('effective_from', '<', $from)
                    ->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
                PayrollEmployeeComponent::withoutGlobalScopes()->updateOrCreate(
                    ['employee_id' => $employee->id, 'component_id' => $c['component_id'], 'effective_from' => $from],
                    ['company_id' => $employee->company_id, 'amount' => $c['amount'] ?? null, 'rate' => $c['rate'] ?? null, 'effective_to' => null,
                        'notes' => "From structure {$structure->name}"],
                );
            }
        });
    }
}
