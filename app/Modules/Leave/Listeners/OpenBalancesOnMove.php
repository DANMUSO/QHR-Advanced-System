<?php

namespace App\Modules\Leave\Listeners;

use App\Modules\Leave\Services\LeaveBalanceService;

/**
 * EmployeeTransferred (another company) and EmployeeReinstated both create a new employee record.
 * Transfer: carry the current-year balances when HR chose to (options.carry_leave_balances), else open fresh
 * balances. Reinstatement always starts fresh (HR-FEATURES §2.8).
 */
class OpenBalancesOnMove
{
    public function __construct(private LeaveBalanceService $balances) {}

    public function transferred(object $event): void
    {
        $this->balances->transferBalances($event->employee, $event->previous, (bool) ($event->options['carry_leave_balances'] ?? false));
    }

    public function reinstated(object $event): void
    {
        $this->balances->transferBalances($event->employee, $event->previous, false);
    }
}
