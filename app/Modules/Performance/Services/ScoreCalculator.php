<?php

namespace App\Modules\Performance\Services;

/**
 * Pure scoring maths for the Performance module (no database access, so it is unit-testable).
 *
 *  - achievement % = achieved / target × 100, floored at 0 and capped at the cycle's max (e.g. 120 %)
 *  - goal score    = achievement % × objective weight / 100
 *  - roll-up       = sum of subordinates' achieved values (average for % units or equal splits)
 *  - KPI score     = objective-weighted average of achievement % (weights normalised over the objectives present)
 *  - final score   = KPI score × kpi weight + competency score × competency weight (default 70 / 30)
 */
class ScoreCalculator
{
    public const EPSILON = 0.005;

    public static function achievementPct(?float $achieved, ?float $target, float $capPct = 120): float
    {
        if ($achieved === null || $target === null || $target <= 0) {
            return 0.0;
        }

        return round(max(0.0, min($achieved / $target * 100, $capPct)), 2);
    }

    public static function goalScore(float $achievementPct, float $objectiveWeight): float
    {
        return round($achievementPct * $objectiveWeight / 100, 2);
    }

    /**
     * Supervisor's achieved value on a cascaded KPI from the subordinates' achieved values.
     *
     * @param  array<float|null>  $values
     */
    public static function rollup(array $values, bool $average): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if (! $values) {
            return null;
        }
        $sum = array_sum($values);

        return round($average ? $sum / count($values) : $sum, 2);
    }

    /** Whether a roll-up averages (percentage units, or every subordinate holds the full target) instead of summing. */
    public static function rollupAverages(bool $isPercentage, ?string $split): bool
    {
        return $isPercentage || $split === 'equal';
    }

    /**
     * Validates a distributed split. Returns an error message, or null when valid.
     * Shares must be positive and, except for percentage units, their sum (plus anything already
     * allocated to other staff) may not exceed the target.
     *
     * @param  array<float>  $shares
     */
    public static function distributionError(float $target, array $shares, bool $isPercentage, float $alreadyAllocated = 0): ?string
    {
        if (! $shares) {
            return 'Select at least one employee.';
        }
        foreach ($shares as $share) {
            if (! is_numeric($share) || $share <= 0) {
                return 'Every share must be greater than zero.';
            }
        }
        if ($isPercentage) {
            return null;
        }
        $total = array_sum($shares) + $alreadyAllocated;
        if ($total - $target > self::EPSILON) {
            $available = max(0, $target - $alreadyAllocated);

            return sprintf('The shares total %s but only %s of the target %s is available to distribute.',
                self::fmt(array_sum($shares)), self::fmt($available), self::fmt($target));
        }

        return null;
    }

    /** @param array<float> $weights */
    public static function weightsComplete(array $weights): bool
    {
        return abs(array_sum($weights) - 100) < self::EPSILON;
    }

    /**
     * Objective-weighted KPI score.
     *
     * @param  array<int, array{weight: float, pcts: array<float>}>  $objectives
     */
    public static function weightedKpiScore(array $objectives): ?float
    {
        $weightSum = 0.0;
        $total = 0.0;
        foreach ($objectives as $o) {
            if (! $o['pcts']) {
                continue;
            }
            $avg = array_sum($o['pcts']) / count($o['pcts']);
            $weight = (float) $o['weight'];
            if ($weight <= 0) {
                continue;
            }
            $total += $avg * $weight;
            $weightSum += $weight;
        }

        return $weightSum > 0 ? round($total / $weightSum, 2) : null;
    }

    /** Competency score % from an average rating on a 1..max scale. */
    public static function competencyPct(?float $averageRating, int $maxScale = 5): ?float
    {
        if ($averageRating === null || $maxScale <= 0) {
            return null;
        }

        return round($averageRating / $maxScale * 100, 2);
    }

    public static function finalScore(?float $kpiScore, ?float $competencyScore, float $kpiWeight = 70, float $competencyWeight = 30): ?float
    {
        if ($kpiScore === null && $competencyScore === null) {
            return null;
        }
        if ($competencyScore === null) {
            return round($kpiScore, 2);
        }
        if ($kpiScore === null) {
            return round($competencyScore, 2);
        }
        $weights = $kpiWeight + $competencyWeight;
        if ($weights <= 0) {
            return round(($kpiScore + $competencyScore) / 2, 2);
        }

        return round(($kpiScore * $kpiWeight + $competencyScore * $competencyWeight) / $weights, 2);
    }

    /**
     * Rating band for a score.
     *
     * @param  array<string, float>  $bands  name => minimum score
     */
    public static function band(?float $score, array $bands): ?string
    {
        if ($score === null) {
            return null;
        }
        arsort($bands);
        foreach ($bands as $name => $min) {
            if ($score + self::EPSILON >= $min) {
                return $name;
            }
        }

        return array_key_last($bands);
    }

    public const DEFAULT_BANDS = [
        'Outstanding' => 90, 'Exceeds Expectations' => 75, 'Meets Expectations' => 60,
        'Below Expectations' => 40, 'Unsatisfactory' => 0,
    ];

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
    }
}
