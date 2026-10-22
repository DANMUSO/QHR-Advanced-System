<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kenya statutory tables. Global (statute applies to every company); each row is effective-dated.
        Schema::create('payroll_statutory_rates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);                         // paye_band | personal_relief | insurance_relief | nssf | shif | ahl | pension_cap | disability_exemption
            $table->string('name');
            $table->decimal('lower_limit', 14, 2)->nullable();  // band lower bound (exclusive) / NSSF LEL
            $table->decimal('upper_limit', 14, 2)->nullable();  // band upper bound (inclusive) / NSSF UEL; null = no limit
            $table->decimal('rate', 8, 4)->nullable();          // employee % / tax %
            $table->decimal('employer_rate', 8, 4)->nullable(); // employer %
            $table->decimal('amount', 14, 2)->nullable();       // fixed amount / minimum
            $table->decimal('cap', 14, 2)->nullable();          // maximum
            $table->boolean('tax_deductible')->default(false);  // deducted from taxable pay before PAYE
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['type', 'effective_from']);
        });

        Schema::create('payroll_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('category', 30);                      // earning | deduction | employer_contribution
            $table->string('calculation', 30)->default('fixed'); // fixed | percent_of_basic | percent_of_gross | formula
            $table->decimal('default_amount', 14, 2)->nullable();
            $table->decimal('default_rate', 8, 4)->nullable();
            $table->string('formula', 500)->nullable();
            $table->string('role', 40)->nullable();               // overtime | pension_employee | pension_employer | insurance_premium | helb | loan | advance | sacco | bonus | arrears | leave_encashment | notice_pay
            $table->boolean('taxable')->default(true);
            $table->boolean('pensionable')->default(false);
            $table->boolean('recurring')->default(true);
            $table->string('gl_account', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('payroll_pay_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->string('payment_method', 20)->default('bank'); // bank | mpesa | cash
            $table->string('bank_name')->nullable();
            $table->string('bank_code', 20)->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('branch_code', 20)->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('mpesa_phone', 20)->nullable();
            $table->boolean('disability_exempt')->default(false);
            $table->string('disability_certificate', 60)->nullable();
            $table->decimal('pension_employee_percent', 6, 3)->default(0);
            $table->decimal('pension_employer_percent', 6, 3)->default(0);
            $table->boolean('on_hold')->default(false);           // excluded from runs (e.g. suspended pay)
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_salary_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('previous_salary', 14, 2)->nullable();
            $table->decimal('basic_salary', 14, 2);
            $table->date('effective_date');
            $table->string('reason', 30);                          // initial | promotion | increment | adjustment
            $table->string('notes', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_date']);
        });

        Schema::create('payroll_employee_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('payroll_components')->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('rate', 8, 4)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('period');                                // first day of the pay month
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('type', 20)->default('regular');        // regular | off_cycle
            $table->string('off_cycle_reason', 30)->nullable();    // bonus | arrears | correction | final_dues
            $table->foreignId('exit_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('options')->nullable();                   // final dues inputs etc.
            $table->string('status', 20)->default('draft');        // draft | calculated | reviewed | approved | paid
            $table->boolean('needs_recalculation')->default(false);
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('total_gross', 16, 2)->default(0);
            $table->decimal('total_deductions', 16, 2)->default(0);
            $table->decimal('total_net', 16, 2)->default(0);
            $table->decimal('total_paye', 16, 2)->default(0);
            $table->decimal('total_employer_cost', 16, 2)->default(0);
            $table->unsignedInteger('exception_count')->default(0);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('payslips_emailed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'period']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payroll_run_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('basic_salary', 14, 2)->default(0);    // contractual monthly basic
            $table->decimal('basic_pay', 14, 2)->default(0);       // basic earned this period
            $table->decimal('days_in_period', 6, 2)->default(0);
            $table->decimal('days_worked', 6, 2)->default(0);
            $table->decimal('working_days', 6, 2)->default(0);
            $table->decimal('unpaid_days', 6, 2)->default(0);
            $table->decimal('half_pay_days', 6, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('overtime_pay', 14, 2)->default(0);
            $table->decimal('gross_pay', 14, 2)->default(0);
            $table->decimal('non_taxable_pay', 14, 2)->default(0);
            $table->decimal('nssf_employee', 14, 2)->default(0);
            $table->decimal('nssf_tier1', 14, 2)->default(0);
            $table->decimal('nssf_tier2', 14, 2)->default(0);
            $table->decimal('nssf_employer', 14, 2)->default(0);
            $table->decimal('shif', 14, 2)->default(0);
            $table->decimal('ahl_employee', 14, 2)->default(0);
            $table->decimal('ahl_employer', 14, 2)->default(0);
            $table->decimal('pension_employee', 14, 2)->default(0);
            $table->decimal('pension_employer', 14, 2)->default(0);
            $table->decimal('allowable_deductions', 14, 2)->default(0);
            $table->decimal('taxable_pay', 14, 2)->default(0);
            $table->decimal('tax_charged', 14, 2)->default(0);
            $table->decimal('personal_relief', 14, 2)->default(0);
            $table->decimal('insurance_relief', 14, 2)->default(0);
            $table->decimal('paye', 14, 2)->default(0);
            $table->decimal('helb', 14, 2)->default(0);
            $table->decimal('other_deductions', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);
            $table->decimal('employer_cost', 14, 2)->default(0);
            $table->string('payment_method', 20)->nullable();
            $table->json('payment_details')->nullable();           // snapshot of bank / M-Pesa details
            $table->json('inputs')->nullable();                    // payroll_inputs collected from other modules
            $table->json('flags')->nullable();                     // exceptions
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['employee_id', 'payroll_run_id']);
        });

        Schema::create('payroll_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()->constrained('payroll_components')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('category', 30);    // earning | deduction | statutory | employer | relief
            $table->decimal('amount', 14, 2);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->boolean('taxable')->default(true);
            $table->string('source', 30)->nullable(); // basic | component | one_off | overtime | loan | advance | statutory
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('gl_account', 30)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->index(['payroll_run_employee_id', 'category']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('payroll_components')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()->constrained('payroll_components')->nullOnDelete();
            $table->string('reference', 40);
            $table->string('description')->nullable();
            $table->decimal('principal', 14, 2);
            $table->string('interest_method', 20)->default('flat'); // flat | reducing
            $table->decimal('interest_rate', 8, 4)->default(0);     // annual %
            $table->unsignedSmallInteger('instalments');
            $table->date('start_period');
            $table->decimal('total_interest', 14, 2)->default(0);
            $table->decimal('total_repayable', 14, 2)->default(0);
            $table->decimal('amount_repaid', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('status', 20)->default('active');        // active | paused | settled | completed
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('payroll_loan_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('period');
            $table->decimal('principal', 14, 2);
            $table->decimal('interest', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('status', 20)->default('pending');       // pending | deducted | settled
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('deducted_at')->nullable();
            $table->index(['payroll_loan_id', 'status']);
        });

        Schema::create('payroll_salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending');       // pending | approved | rejected | recovered | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->date('recovery_period')->nullable();
            $table->foreignId('recovered_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach ([
            'payroll_salary_advances', 'payroll_loan_instalments', 'payroll_loans', 'payroll_adjustments',
            'payroll_run_items', 'payroll_run_employees', 'payroll_runs', 'payroll_employee_components',
            'payroll_salary_history', 'payroll_pay_profiles', 'payroll_components', 'payroll_statutory_rates',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
