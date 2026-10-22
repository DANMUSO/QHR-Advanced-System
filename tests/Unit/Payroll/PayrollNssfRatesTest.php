<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\Calculation\CalculationInput;
use App\Modules\Payroll\Services\Calculation\PayrollCalculator;
use App\Modules\Payroll\Services\Calculation\StatutoryRates;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** NSSF Act 2013 phase-in: Year 3 (LEL 8,000 / UEL 72,000) and Year 4 from Feb 2026 (LEL 9,000 / UEL 108,000), 6% + 6%. */
class PayrollNssfRatesTest extends TestCase
{
    private function calc(float $basic, StatutoryRates $rates): array
    {
        $input = CalculationInput::make([
            'basicSalary' => $basic,
            'periodStart' => CarbonImmutable::parse('2026-03-01'),
            'periodEnd' => CarbonImmutable::parse('2026-03-31'),
            'workingDays' => 22,
        ]);

        return (new PayrollCalculator)->calculate($input, $rates);
    }

    private function year4(): StatutoryRates
    {
        return StatutoryRates::kenyaDefaults(['nssfLowerLimit' => 9000, 'nssfUpperLimit' => 108000]);
    }

    private function year3(): StatutoryRates
    {
        return StatutoryRates::kenyaDefaults(['nssfLowerLimit' => 8000, 'nssfUpperLimit' => 72000]);
    }

    public function test_defaults_are_year4_limits(): void
    {
        $d = StatutoryRates::kenyaDefaults();
        $this->assertSame(9000.0, $d->nssfLowerLimit);
        $this->assertSame(108000.0, $d->nssfUpperLimit);
        $this->assertSame(6.0, $d->nssfRate);
        $this->assertSame(6.0, $d->nssfEmployerRate);
    }

    public function test_year4_mid_salary_splits_tiers(): void
    {
        $r = $this->calc(50000, $this->year4());
        $this->assertSame(540.0, $r['nssf_tier1']);              // 9,000 x 6%
        $this->assertSame(2460.0, $r['nssf_tier2']);             // (50,000 - 9,000) x 6%
        $this->assertSame(3000.0, $r['nssf_employee']);
        $this->assertSame(3000.0, $r['nssf_employer']);          // employer matches 6% per tier
    }

    public function test_year4_caps_at_upper_earnings_limit(): void
    {
        $r = $this->calc(200000, $this->year4());
        $this->assertSame(540.0, $r['nssf_tier1']);
        $this->assertSame(5940.0, $r['nssf_tier2']);             // (108,000 - 9,000) x 6%
        $this->assertSame(6480.0, $r['nssf_employee']);
        $this->assertSame(6480.0, $r['nssf_employer']);
    }

    public function test_year4_below_lower_limit_has_no_tier2(): void
    {
        $r = $this->calc(7000, $this->year4());
        $this->assertSame(420.0, $r['nssf_tier1']);
        $this->assertSame(0.0, $r['nssf_tier2']);
    }

    public function test_year3_rates_still_apply_when_supplied(): void
    {
        $r = $this->calc(100000, $this->year3());
        $this->assertSame(480.0, $r['nssf_tier1']);              // 8,000 x 6%
        $this->assertSame(3840.0, $r['nssf_tier2']);             // (72,000 - 8,000) x 6%
        $this->assertSame(4320.0, $r['nssf_employee']);
    }

    public function test_nssf_is_deducted_before_paye(): void
    {
        $r = $this->calc(50000, $this->year4());
        // taxable = gross - NSSF - SHIF - AHL
        $this->assertEqualsWithDelta(50000 - 3000 - 1375 - 750, $r['taxable_pay'], 0.01);
    }
}
