<?php

namespace App\Modules\Employees\Events;

use App\Models\Employee;
use App\Models\EmployeeExit;
use Illuminate\Foundation\Events\Dispatchable;

class EmployeeExited
{
    use Dispatchable;

    public function __construct(public Employee $employee, public EmployeeExit $exit) {}
}
