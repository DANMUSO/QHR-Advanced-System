<?php

namespace App\Modules\Claims\Dashboard;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Claims\Services\ClaimLimits;

/** The signed-in employee's medical claim balance. */
class ClaimBalanceWidget extends Widget
{
    public function __construct(private ClaimLimits $limits) {}

    public function key(): string   { return 'claim_balance'; }
    public function title(): string { return 'Medical claim balance'; }
    public function size(): string  { return 'sm'; }

    public function visible(User $user): bool
    {
        return $user->employee !== null && $user->can('claims.submit');
    }

    public function data(User $user): array
    {
        $employee = $user->employee;
        $balance  = $this->limits->balanceFor($employee);

        return [
            'annual_limit'     => $balance['annual_limit'],
            'annual_used'      => $balance['annual_used'],
            'annual_balance'   => $balance['annual_balance'],
            'annual_pending'   => $balance['annual_pending'],
            'annual_available' => $balance['annual_available'],
            'monthly_limit'    => $balance['monthly_limit'],
            'monthly_used'     => $balance['monthly_used'],
            'monthly_balance'  => $balance['monthly_balance'],
            'monthly_pending'  => $balance['monthly_pending'],
            'monthly_available' => $balance['monthly_available'],
            'utilisation'      => $balance['utilisation'],
            'year'             => $balance['year'],
            'month_label'      => $balance['month_label'],
        ];
    }
}
