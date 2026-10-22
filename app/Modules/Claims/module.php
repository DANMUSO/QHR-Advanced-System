<?php

use App\Models\Employee;
use App\Modules\Claims\Console\ResetClaimBalances;
use App\Modules\Claims\Models\ClaimLimit;
use App\Modules\Claims\Models\MedicalClaim;
use Illuminate\Console\Scheduling\Schedule;

return [
    'name' => 'Claims',
    'order' => 40,
    'approval_providers' => [App\Modules\Claims\Dashboard\ClaimApprovals::class],
    'widgets' => [
        App\Modules\Claims\Dashboard\ClaimBalanceWidget::class,
        App\Modules\Claims\Dashboard\ClaimsAwaitingPaymentWidget::class,
    ],

    'boot' => function () {
        Employee::resolveRelationUsing('medicalClaims', fn (Employee $e) => $e->hasMany(MedicalClaim::class));
        Employee::resolveRelationUsing('claimLimit', fn (Employee $e) => $e->hasOne(ClaimLimit::class));

        \Illuminate\Console\Application::starting(function ($artisan) {
            $artisan->resolve(ResetClaimBalances::class);
        });
    },

    'schedule' => function (Schedule $s) {
        // Reset claim balances on 1 Jan each year (§12)
        $s->command('claims:reset-balances')->yearlyOn(1, 1, '01:00')->withoutOverlapping();
    },
];
