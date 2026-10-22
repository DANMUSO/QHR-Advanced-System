<?php

namespace App\Modules\Leave\Dashboard;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveBalanceService;

/** The signed-in employee's leave balances. */
class LeaveBalancesWidget extends Widget
{
    public function __construct(private LeaveBalanceService $balances) {}

    public function key(): string
    {
        return 'leave_balances';
    }

    public function title(): string
    {
        return 'My leave balances';
    }

    public function visible(User $user): bool
    {
        return $user->employee !== null && $user->can('leave.apply');
    }

    public function data(User $user): array
    {
        $employee = $user->employee;
        $items = $this->balances->summary($employee)->filter(fn ($b) => $b['has_balance'] && ! $b['draws_from'])->values();
        $next = LeaveRequest::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->whereIn('status', [...LeaveRequest::OPEN, LeaveRequest::APPROVED])->whereDate('end_date', '>=', today())
            ->with('leaveType:id,name')->orderBy('start_date')->first();

        return [
            'year' => now()->year,
            'items' => $items->map(fn ($b) => [
                'name' => $b['name'], 'code' => $b['code'], 'color' => $b['color'],
                'available' => $b['available'], 'projected' => $b['projected'], 'pending' => $b['pending'],
                'taken' => $b['taken'], 'earned' => $b['earned'], 'entitlement' => $b['entitlement'],
            ]),
            'next' => $next ? [
                'type' => $next->leaveType?->name, 'start_date' => $next->start_date->toDateString(),
                'end_date' => $next->end_date->toDateString(), 'days' => $next->days, 'status' => $next->status,
                'status_label' => LeaveRequest::STATUSES[$next->status] ?? $next->status,
            ] : null,
        ];
    }
}
