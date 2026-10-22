<?php

namespace App\Modules\Claims\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Claims\Models\ClaimLimit;
use App\Modules\Claims\Models\MedicalClaim;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Limits and balances. Balances are always computed from the claims table, so a new calendar
 * year automatically starts with the full annual limit (no stored balances to reset).
 *
 *   approved  = HR-approved or paid claims (approved_amount ?? total) — finance rejections restore the balance
 *   pending   = submitted claims not yet decided (reserved, so employees cannot over-claim)
 *   balance   = annual limit − approved                    (legacy definition, shown as "balance")
 *   available = annual limit − approved − pending          (what a new claim may use)
 */
class ClaimLimits
{
    /** @return array{annual: float, monthly: float, overridden: bool, default_annual: float, default_monthly: float} */
    public function limitsFor(Employee $employee): array
    {
        $company = $employee->relationLoaded('company') ? $employee->company : Company::find($employee->company_id);
        $defaultAnnual = (float) ($company?->setting('medical_annual_limit') ?? Company::DEFAULT_SETTINGS['medical_annual_limit']);
        $defaultMonthly = (float) ($company?->setting('medical_monthly_limit') ?? Company::DEFAULT_SETTINGS['medical_monthly_limit']);
        $override = ClaimLimit::withoutGlobalScope('company')->where('employee_id', $employee->id)->first();

        return [
            'annual' => $override?->annual_limit !== null ? (float) $override->annual_limit : $defaultAnnual,
            'monthly' => $override?->monthly_limit !== null ? (float) $override->monthly_limit : $defaultMonthly,
            'overridden' => $override !== null && ($override->annual_limit !== null || $override->monthly_limit !== null),
            'default_annual' => $defaultAnnual,
            'default_monthly' => $defaultMonthly,
            'notes' => $override?->notes,
        ];
    }

    /**
     * Full balance picture for the year of $date (default today) and the month of $date.
     * $excludeClaimId leaves one claim out (when re-validating an edited claim).
     */
    public function balanceFor(Employee $employee, ?CarbonInterface $date = null, ?int $excludeClaimId = null): array
    {
        $date = $date ? Carbon::parse($date) : now();
        $limits = $this->limitsFor($employee);

        $claims = MedicalClaim::withoutGlobalScope('company')
            ->where('employee_id', $employee->id)
            ->inYear($date->year)
            ->whereIn('status', [...MedicalClaim::APPROVED_STATUSES, MedicalClaim::SUBMITTED])
            ->when($excludeClaimId, fn ($q) => $q->whereKeyNot($excludeClaimId))
            ->get(['id', 'status', 'claim_date', 'total_amount', 'approved_amount']);

        $sum = fn (Collection $c) => round($c->sum(fn (MedicalClaim $m) => $m->effectiveAmount()), 2);
        $approved = $claims->whereIn('status', MedicalClaim::APPROVED_STATUSES);
        $pending = $claims->where('status', MedicalClaim::SUBMITTED);
        $inMonth = fn (Collection $c) => $c->filter(fn ($m) => $m->claim_date->format('Y-m') === $date->format('Y-m'));

        $yearApproved = $sum($approved);
        $yearPending = $sum($pending);
        $monthApproved = $sum($inMonth($approved));
        $monthPending = $sum($inMonth($pending));

        return [
            'year' => $date->year,
            'month' => $date->format('Y-m'),
            'month_label' => $date->format('F Y'),
            'annual_limit' => $limits['annual'],
            'monthly_limit' => $limits['monthly'],
            'overridden' => $limits['overridden'],
            'annual_used' => $yearApproved,
            'annual_pending' => $yearPending,
            'annual_balance' => round(max(0, $limits['annual'] - $yearApproved), 2),
            'annual_available' => round(max(0, $limits['annual'] - $yearApproved - $yearPending), 2),
            'monthly_used' => $monthApproved,
            'monthly_pending' => $monthPending,
            'monthly_balance' => round(max(0, $limits['monthly'] - $monthApproved), 2),
            'monthly_available' => round(max(0, $limits['monthly'] - $monthApproved - $monthPending), 2),
            'utilisation' => $limits['annual'] > 0 ? round($yearApproved / $limits['annual'] * 100, 1) : 0,
        ];
    }

    /** Messages for each limit an amount would break (empty = OK). */
    public function violations(Employee $employee, float $amount, CarbonInterface $date, ?int $excludeClaimId = null, bool $includePending = true): array
    {
        $b = $this->balanceFor($employee, $date, $excludeClaimId);
        $annualRoom = $includePending ? $b['annual_available'] : $b['annual_balance'];
        $monthlyRoom = $includePending ? $b['monthly_available'] : $b['monthly_balance'];
        $errors = [];
        if ($amount > $annualRoom + 0.001) {
            $errors[] = sprintf('The amount (KES %s) exceeds the remaining annual balance of KES %s for %d.', number_format($amount, 2), number_format($annualRoom, 2), $b['year']);
        }
        if ($amount > $monthlyRoom + 0.001) {
            $errors[] = sprintf('The amount (KES %s) exceeds the remaining monthly limit of KES %s for %s.', number_format($amount, 2), number_format($monthlyRoom, 2), $b['month_label']);
        }

        return $errors;
    }
}
