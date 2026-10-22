<?php

namespace App\Modules\Claims\Dashboard;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Claims\Models\MedicalClaim;
use Illuminate\Support\Facades\DB;

/** Claims awaiting Finance payment — for Finance dashboard. */
class ClaimsAwaitingPaymentWidget extends Widget
{
    public function key(): string   { return 'claims_awaiting_payment'; }
    public function title(): string { return 'Claims awaiting payment'; }
    public function size(): string  { return 'sm'; }

    public function visible(User $user): bool
    {
        return $user->can('claims.pay') || $user->can('dashboard.finance');
    }

    public function data(User $user): array
    {
        $claims = MedicalClaim::where('status', MedicalClaim::HR_APPROVED)
            ->with('employee:id,first_name,last_name,phone')
            ->orderBy('hr_decided_at')
            ->limit(10)
            ->get();

        $total = MedicalClaim::where('status', MedicalClaim::HR_APPROVED)->count();
        $amount = MedicalClaim::where('status', MedicalClaim::HR_APPROVED)
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(approved_amount, total_amount)'));

        return [
            'total'  => $total,
            'amount' => round((float) $amount, 2),
            'items'  => $claims->map(fn ($c) => [
                'id'            => $c->id,
                'reference'     => $c->reference,
                'employee_name' => $c->employee?->full_name,
                'phone'         => $c->employee?->phone,
                'amount'        => (float) ($c->approved_amount ?? $c->total_amount),
                'approved_at'   => $c->hr_decided_at?->toDateString(),
            ])->values(),
        ];
    }
}
