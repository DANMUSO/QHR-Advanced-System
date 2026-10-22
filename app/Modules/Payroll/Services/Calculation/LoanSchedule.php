<?php

namespace App\Modules\Payroll\Services\Calculation;

use Carbon\CarbonImmutable;

/**
 * Pure amortisation schedule builder for staff loans.
 *  - flat:     interest = principal × annual rate × (instalments / 12), spread evenly.
 *  - reducing: equal monthly instalments (annuity) with interest on the outstanding principal.
 * The final instalment absorbs rounding so totals reconcile exactly.
 *
 * Each row carries principal + interest = amount. `balance_after` is the amount still repayable
 * (principal AND interest) once that instalment has been deducted; `principal_balance` is the
 * outstanding principal only.
 */
final class LoanSchedule
{
    /** @return array{instalment: float, total_interest: float, total_repayable: float, rows: array<int, array>} */
    public static function build(float $principal, string $method, float $annualRate, int $instalments, string $startPeriod): array
    {
        if ($principal <= 0 || $instalments < 1) {
            throw new \InvalidArgumentException('Principal and number of instalments must be positive.');
        }
        $start = CarbonImmutable::parse($startPeriod)->startOfMonth();
        $parts = [];
        $balance = round($principal, 2);

        if ($method === 'reducing' && $annualRate > 0) {
            $r = $annualRate / 100 / 12;
            $payment = round($principal * $r / (1 - (1 + $r) ** -$instalments), 2);
            for ($i = 1; $i <= $instalments; $i++) {
                $interest = round($balance * $r, 2);
                $principalPart = $i === $instalments ? $balance : round(min($balance, $payment - $interest), 2);
                $balance = round($balance - $principalPart, 2);
                $parts[] = [$principalPart, $interest, $balance];
            }
        } else {
            $totalInterest = $method === 'flat' ? round($principal * $annualRate / 100 * $instalments / 12, 2) : 0.0;
            $p = floor($principal / $instalments * 100) / 100;
            $in = floor($totalInterest / $instalments * 100) / 100;
            $interestLeft = $totalInterest;
            for ($i = 1; $i <= $instalments; $i++) {
                $last = $i === $instalments;
                $principalPart = $last ? $balance : $p;
                $interest = $last ? round($interestLeft, 2) : $in;
                $interestLeft = round($interestLeft - $interest, 2);
                $balance = round($balance - $principalPart, 2);
                $parts[] = [$principalPart, $interest, $balance];
            }
        }

        $totalInterest = round(array_sum(array_column($parts, 1)), 2);
        $totalRepayable = round($principal + $totalInterest, 2);

        $rows = [];
        $outstanding = $totalRepayable;
        foreach ($parts as $idx => [$principalPart, $interest, $principalBalance]) {
            $amount = round($principalPart + $interest, 2);
            $outstanding = round($outstanding - $amount, 2);
            $rows[] = [
                'number' => $idx + 1,
                'period' => $start->addMonthsNoOverflow($idx)->toDateString(),
                'principal' => round($principalPart, 2),
                'interest' => round($interest, 2),
                'amount' => $amount,
                'balance_after' => max(0.0, $outstanding),
                'principal_balance' => max(0.0, round($principalBalance, 2)),
            ];
        }

        return [
            'instalment' => $rows[0]['amount'],
            'total_interest' => $totalInterest,
            'total_repayable' => $totalRepayable,
            'rows' => $rows,
        ];
    }
}
