<?php

namespace App\Modules\Benefits\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Benefits\Models\ExpenseClaim;
use Illuminate\Database\Eloquent\Builder;

/** Approved expense claims awaiting Finance (claims.pay). Opened on the payments page, since paying needs a method. */
class ExpensePayments implements ApprovalProvider
{
    public function pendingFor(User $user, int $limit = 10): array
    {
        return $this->query($user)?->with('employee:id,first_name,last_name')->orderBy('decided_at')->limit($limit)->get()
            ->map(fn (ExpenseClaim $e) => [
                'id' => $e->id,
                'type' => 'expense_payment',
                'title' => 'Pay '.($e->employee?->full_name ?? 'employee').' — expense KES '.number_format((float) $e->amount),
                'subtitle' => $e->description,
                'requested_at' => $e->decided_at ?? $e->updated_at,
                'url' => '/expenses/payments',
                'approve_endpoint' => null,
                'reject_endpoint' => null,
            ])->values()->all() ?? [];
    }

    public function countFor(User $user): int
    {
        return $this->query($user)?->count() ?? 0;
    }

    private function query(User $user): ?Builder
    {
        if (! $user->can('claims.pay')) {
            return null;
        }

        return ExpenseClaim::where('status', 'approved')
            ->when($user->employee, fn ($q) => $q->where('employee_id', '!=', $user->employee->id));
    }
}
