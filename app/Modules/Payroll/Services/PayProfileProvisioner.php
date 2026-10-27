<?php

namespace App\Modules\Payroll\Services;

use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Support\PayrollDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Creates pay profiles from lifecycle events (hire, re-instatement, company transfer). Idempotent per employee record. */
class PayProfileProvisioner
{
    /** New hire: empty profile, seeded with the contract's agreed gross salary when there is one. */
    public function forHire(Employee $employee, ?float $initialPay = null): PayrollPayProfile
    {
        return DB::transaction(function () use ($employee, $initialPay) {
            $profile = PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $employee->id)->first();
            if ($profile) {
                return $profile;
            }
            $basic = $initialPay ?? $this->contractSalary($employee);
            $profile = (new PayrollPayProfile(['employee_id' => $employee->id, 'basic_salary' => $basic ?? 0]))
                ->forceFill(['company_id' => $employee->company_id]);
            $profile->save();

            if ($basic !== null && $basic > 0) {
                PayrollSalaryHistory::withoutGlobalScopes()->create([
                    'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'previous_salary' => null,
                    'basic_salary' => $basic, 'effective_date' => $employee->date_of_joining?->toDateString() ?? now()->toDateString(),
                    'reason' => 'initial', 'notes' => 'Seeded from the employment contract at hire',
                ]);
            }

            // A pay structure for the employee's employment level (grade) is applied automatically: its components,
            // and its basic as the initial salary unless the contract already fixed one.
            if ($structure = app(PayStructureService::class)->matchFor($employee)) {
                app(PayStructureService::class)->apply($structure, $profile,
                    $employee->date_of_joining?->toDateString() ?? now()->toDateString(), null, setBasic: ! ($basic > 0));
            }

            return $profile->fresh();
        });
    }

    /**
     * Re-instated or transferred record: copies the previous record's pay profile (payment details, pension, current basic)
     * and its active components, effective from the new joining date. The new record is never on hold.
     */
    public function carryOver(Employee $employee, Employee $previous, string $reason): ?PayrollPayProfile
    {
        $old = PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $previous->id)->first();
        $existing = PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $employee->id)->first();
        if ($existing) {
            $existing->update(['on_hold' => false]);

            return $existing;
        }
        if (! $old) {
            return $this->forHire($employee);
        }

        $start = CarbonImmutable::parse($employee->date_of_joining ?? now())->startOfDay();
        $sameCompany = (int) $employee->company_id === (int) $previous->company_id;

        return DB::transaction(function () use ($employee, $previous, $old, $start, $sameCompany, $reason) {
            $basic = $this->currentBasic($previous, $old);
            $attributes = collect($old->getAttributes())
                ->except(['id', 'company_id', 'employee_id', 'structure_id', 'on_hold', 'basic_salary', 'created_at', 'updated_at'])->all();
            $profile = (new PayrollPayProfile($attributes + ['employee_id' => $employee->id, 'basic_salary' => $basic, 'on_hold' => false]))
                ->forceFill(['company_id' => $employee->company_id, 'structure_id' => $sameCompany ? $old->structure_id : null]);
            $profile->save();

            PayrollSalaryHistory::withoutGlobalScopes()->create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'previous_salary' => $basic,
                'basic_salary' => $basic, 'effective_date' => $start->toDateString(), 'reason' => $reason,
                'notes' => ($reason === 'transfer' ? 'Company transfer' : 'Re-instatement').' from record #'.$previous->id,
            ]);

            // Components: same company keeps the component; another company maps by code (its defaults are created first).
            if (! $sameCompany) {
                PayrollDefaults::ensureComponents($employee->company_id);
            }
            $targets = PayrollComponent::withoutGlobalScopes()->where('company_id', $employee->company_id)->get()->keyBy('code');
            $assignments = PayrollEmployeeComponent::withoutGlobalScopes()->with(['component' => fn ($q) => $q->withoutGlobalScopes()])
                ->where('employee_id', $previous->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start->subDay()->toDateString()))->get();
            foreach ($assignments as $a) {
                $component = $sameCompany ? $a->component : ($a->component ? $targets->get($a->component->code) : null);
                if (! $component) {
                    continue;
                }
                PayrollEmployeeComponent::withoutGlobalScopes()->create([
                    'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'component_id' => $component->id,
                    'amount' => $a->amount, 'rate' => $a->rate, 'effective_from' => $start->toDateString(), 'effective_to' => null,
                    'notes' => 'Carried over from record #'.$previous->id,
                ]);
            }
            activity('payroll')->performedOn($profile)->withProperties(['from_employee_id' => $previous->id, 'basic' => $basic, 'reason' => $reason])
                ->log('Pay profile carried over');

            return $profile;
        });
    }

    private function currentBasic(Employee $employee, PayrollPayProfile $profile): float
    {
        $h = PayrollSalaryHistory::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->whereDate('effective_date', '<=', now()->toDateString())->orderByDesc('effective_date')->orderByDesc('id')->value('basic_salary');

        return (float) ($h ?? $profile->basic_salary);
    }

    private function contractSalary(Employee $employee): ?float
    {
        // Contracts belong to the Employees module (relation registered there); payroll works without it.
        try {
            $value = $employee->contracts()->withoutGlobalScope('company')->whereNotNull('gross_salary')
                ->orderByDesc('start_date')->orderByDesc('id')->value('gross_salary');
        } catch (\Throwable) {
            return null;
        }

        return $value !== null ? (float) $value : null;
    }
}
