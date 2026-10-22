<?php

namespace App\Modules\Payroll\Events;

use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a payroll run is approved (and locked). Other modules use it to mark the inputs they fed
 * into the run as settled, e.g. Attendance marks the period's approved overtime as paid.
 */
class PayrollRunApproved
{
    use Dispatchable;

    public function __construct(public PayrollRun $run) {}
}
