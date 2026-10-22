<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Salary advances are recovered in equal monthly instalments; amount_recovered tracks what payroll has deducted so far.
        Schema::table('payroll_salary_advances', function (Blueprint $t) {
            if (! Schema::hasColumn('payroll_salary_advances', 'repayment_months')) {
                $t->unsignedTinyInteger('repayment_months')->default(1)->after('amount');
            }
            if (! Schema::hasColumn('payroll_salary_advances', 'amount_recovered')) {
                $t->decimal('amount_recovered', 14, 2)->default(0)->after('repayment_months');
            }
        });

        // Non-taxable reimbursements (e.g. expense claims paid through payroll) are added to net pay, outside gross.
        Schema::table('payroll_run_employees', function (Blueprint $t) {
            if (! Schema::hasColumn('payroll_run_employees', 'reimbursements')) {
                $t->decimal('reimbursements', 14, 2)->default(0)->after('other_deductions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_employees', fn (Blueprint $t) => $t->dropColumn('reimbursements'));
        Schema::table('payroll_salary_advances', fn (Blueprint $t) => $t->dropColumn(['repayment_months', 'amount_recovered']));
    }
};
