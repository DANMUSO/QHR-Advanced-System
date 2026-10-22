<?php

namespace App\Modules\Payroll\Services\Calculation;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Everything the calculator needs for one employee in one pay period. No database access.
 *
 * components: list of arrays with keys
 *   code, name, category (earning | deduction | employer_contribution), calculation (fixed | percent_of_basic | percent_of_gross | formula),
 *   amount, rate, formula, taxable, pensionable, recurring, role, gl_account, component_id, source ('component' | 'one_off'), source_id
 * deductions: pre-computed deductions (loans, advances): code, name, amount, source, source_id, gl_account, component_id, role
 * prior: amounts already processed for the same employee and month (used by off-cycle runs so tax and caps are cumulative):
 *   gross, taxable_pay, tax_charged, reliefs, paye, nssf_tier1, nssf_tier2, shif, ahl_employee, pension_allowable, insurance_premiums
 */
final class CalculationInput
{
    public float $basicSalary = 0;

    public CarbonImmutable $periodStart;

    public CarbonImmutable $periodEnd;

    public ?CarbonImmutable $employmentStart = null;

    public ?CarbonImmutable $employmentEnd = null;

    public float $workingDays = 22;

    /** Divisor for the hourly overtime rate (hours_per_day × working days, or 225). */
    public float $monthlyHours = 225;

    public float $overtimeMultiplierNormal = 1.5;

    public float $overtimeMultiplierHoliday = 2.0;

    /** Summed payroll_inputs from other modules. */
    public array $inputs = [];

    public array $components = [];

    public array $deductions = [];

    public float $pensionEmployeePercent = 0;

    public float $pensionEmployerPercent = 0;

    public bool $disabilityExempt = false;

    /** Off-cycle runs that only pay one-off items set this to false. */
    public bool $includeBasic = true;

    public bool $prorate = true;

    public array $prior = [];

    public static function make(array $attributes): self
    {
        $in = new self;
        foreach ($attributes as $key => $value) {
            if (! property_exists($in, $key)) {
                throw new \InvalidArgumentException("Unknown calculation input \"$key\".");
            }
            if (in_array($key, ['periodStart', 'periodEnd', 'employmentStart', 'employmentEnd'], true)) {
                $value = $value === null ? null : CarbonImmutable::parse($value instanceof CarbonInterface ? $value->toDateString() : $value)->startOfDay();
            }
            $in->{$key} = $value;
        }
        $in->periodStart ??= CarbonImmutable::now()->startOfMonth();
        $in->periodEnd ??= $in->periodStart->endOfMonth()->startOfDay();

        return $in;
    }

    public function __construct()
    {
        $this->periodStart = CarbonImmutable::now()->startOfMonth();
        $this->periodEnd = $this->periodStart->endOfMonth()->startOfDay();
    }

    public function input(string $key): float
    {
        return (float) ($this->inputs[$key] ?? 0);
    }

    public function prior(string $key): float
    {
        return (float) ($this->prior[$key] ?? 0);
    }
}
