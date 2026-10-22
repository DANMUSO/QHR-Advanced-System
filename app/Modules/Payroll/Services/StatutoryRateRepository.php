<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\StatutoryRate;
use App\Modules\Payroll\Services\Calculation\StatutoryRates;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Loads the statutory rates in force on a date from payroll_statutory_rates (falls back to Kenya defaults per item). */
class StatutoryRateRepository
{
    private array $cache = [];

    public function forDate(CarbonInterface|string $date): StatutoryRates
    {
        $d = Carbon::parse($date)->toDateString();
        if (isset($this->cache[$d])) {
            return $this->cache[$d];
        }

        $rows = StatutoryRate::effectiveOn($d)->orderByDesc('effective_from')->get()->groupBy('type');
        $one = fn (string $type) => $rows->get($type)?->first();
        $defaults = StatutoryRates::kenyaDefaults();
        $args = [];

        $bands = $rows->get('paye_band');
        if ($bands && $bands->isNotEmpty()) {
            $args['payeBands'] = $bands->sortBy('lower_limit')->map(fn ($b) => [
                'lower' => (float) $b->lower_limit, 'upper' => $b->upper_limit === null ? null : (float) $b->upper_limit, 'rate' => (float) $b->rate,
            ])->values()->all();
        }
        if ($r = $one('personal_relief')) {
            $args['personalRelief'] = (float) $r->amount;
        }
        if ($r = $one('insurance_relief')) {
            $args['insuranceReliefRate'] = (float) $r->rate;
            $args['insuranceReliefCap'] = (float) ($r->cap ?? $defaults->insuranceReliefCap);
        }
        if ($r = $one('nssf')) {
            $args['nssfRate'] = (float) $r->rate;
            $args['nssfEmployerRate'] = (float) ($r->employer_rate ?? $r->rate);
            $args['nssfLowerLimit'] = (float) $r->lower_limit;
            $args['nssfUpperLimit'] = (float) $r->upper_limit;
            $args['nssfDeductible'] = (bool) $r->tax_deductible;
        }
        if ($r = $one('shif')) {
            $args['shifRate'] = (float) $r->rate;
            $args['shifMinimum'] = (float) ($r->amount ?? 0);
            $args['shifDeductible'] = (bool) $r->tax_deductible;
        }
        if ($r = $one('ahl')) {
            $args['ahlRate'] = (float) $r->rate;
            $args['ahlEmployerRate'] = (float) ($r->employer_rate ?? $r->rate);
            $args['ahlDeductible'] = (bool) $r->tax_deductible;
        }
        if ($r = $one('pension_cap')) {
            // The monthly cap lives in `cap`; rows entered through the setup screen may only carry `amount`.
            $args['pensionCap'] = (float) ($r->cap ?: $r->amount ?: $defaults->pensionCap);
        }
        if ($r = $one('disability_exemption')) {
            $args['disabilityExemption'] = (float) $r->amount;
        }

        return $this->cache[$d] = StatutoryRates::kenyaDefaults($args);
    }
}
