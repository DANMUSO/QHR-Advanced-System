<?php

namespace App\Modules\Employees\Events;

use App\Models\Employee;
use App\Models\EmployeeExit;
use Illuminate\Foundation\Events\Dispatchable;

class EmployeeTransferred
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $options  e.g. ['carry_leave_balances' => bool] chosen by HR when the move was made
     */
    public function __construct(public Employee $employee, public Employee $previous, public array $options = []) {}
}
