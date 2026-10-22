<?php

namespace App\Modules\Payroll\Services\Calculation;

/**
 * Immutable snapshot of the Kenyan statutory rates in force for a pay period.
 * Built from the payroll_statutory_rates table (see StatutoryRateRepository) or from defaults in tests.
 */
final class StatutoryRates
{
    /**
     * @param  array<int, array{lower: float, upper: ?float, rate: float}>  $payeBands  monthly bands; lower is exclusive, upper inclusive (null = no limit)
     */
    public function __construct(
        public readonly array $payeBands,
        public readonly float $personalRelief = 2400,
        public readonly float $insuranceReliefRate = 15,
        public readonly float $insuranceReliefCap = 5000,
        public readonly float $nssfRate = 6,
        public readonly float $nssfEmployerRate = 6,
        public readonly float $nssfLowerLimit = 9000,
        public readonly float $nssfUpperLimit = 108000,
        public readonly bool $nssfDeductible = true,
        public readonly float $shifRate = 2.75,
        public readonly float $shifMinimum = 300,
        public readonly bool $shifDeductible = true,
        public readonly float $ahlRate = 1.5,
        public readonly float $ahlEmployerRate = 1.5,
        public readonly bool $ahlDeductible = true,
        public readonly float $pensionCap = 30000,
        public readonly float $disabilityExemption = 150000,
    ) {}

    /** Rates in force on a date, read from payroll_statutory_rates by effective date (per-item fallback to Kenya defaults). */
    public static function forDate(mixed $date): self
    {
        return app(\App\Modules\Payroll\Services\StatutoryRateRepository::class)->forDate($date);
    }

    /**
     * Fallback Kenya defaults used only when the payroll_statutory_rates table has no row for an item
     * (Finance Act 2023 bands, NSSF Year 4 limits from Feb 2026, SHIF, AHL). Verify against KRA / NSSF / SHA notices.
     */
    public static function kenyaDefaults(array $overrides = []): self
    {
        $args = array_merge([
            'payeBands' => [
                ['lower' => 0, 'upper' => 24000, 'rate' => 10],
                ['lower' => 24000, 'upper' => 32333, 'rate' => 25],
                ['lower' => 32333, 'upper' => 500000, 'rate' => 30],
                ['lower' => 500000, 'upper' => 800000, 'rate' => 32.5],
                ['lower' => 800000, 'upper' => null, 'rate' => 35],
            ],
        ], $overrides);

        return new self(...$args);
    }

    /** Graduated income tax on a monthly taxable amount, before reliefs. */
    public function tax(float $taxable): float
    {
        if ($taxable <= 0) {
            return 0.0;
        }
        $bands = $this->payeBands;
        usort($bands, fn ($a, $b) => $a['lower'] <=> $b['lower']);
        $tax = 0.0;
        foreach ($bands as $band) {
            $lower = (float) $band['lower'];
            $upper = $band['upper'] === null ? INF : (float) $band['upper'];
            if ($taxable <= $lower) {
                break;
            }
            $tax += (min($taxable, $upper) - $lower) * ((float) $band['rate'] / 100);
        }

        return round($tax, 2);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
