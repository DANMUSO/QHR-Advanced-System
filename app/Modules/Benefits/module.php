<?php

use App\Modules\Benefits\Payroll\ExpenseReimbursementInputs;
use Illuminate\Support\Facades\Event;

return [
    'name' => 'Benefits',
    'order' => 75,
    'approval_providers' => [
        App\Modules\Benefits\Dashboard\ExpenseApprovals::class,
        App\Modules\Benefits\Dashboard\ExpensePayments::class,
    ],

    // Expense claims paid "through payroll" → non-taxable `reimbursements` in the next run.
    'payroll_inputs' => [ExpenseReimbursementInputs::class],

    'boot' => function () {
        // By class name, so Benefits does not depend on the Payroll module being installed.
        Event::listen('App\Modules\Payroll\Events\PayrollRunApproved', [ExpenseReimbursementInputs::class, 'settle']);
    },
];
