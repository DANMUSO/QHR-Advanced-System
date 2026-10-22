<?php

namespace App\Modules\Leave\Events;

/**
 * Fired after leave is approved, cancelled or recalled, so other modules (Attendance) can
 * recompute the employee's days in [from, to]. Dispatched through SideEffects, so a listener
 * failure is logged and never breaks the leave action.
 */
class LeaveDatesChanged
{
    public function __construct(
        public readonly int $employeeId,
        public readonly string $from,
        public readonly string $to,
        public readonly string $reason,
    ) {}
}
