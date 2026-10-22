<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->string('category', 40);
            $table->decimal('amount', 14, 2);
            $table->date('expense_date');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('submitted'); // submitted | approved | rejected | paid
            $table->foreignId('approver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status']);
        });

        Schema::create('travel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('destination', 150);
            $table->string('purpose', 255);
            $table->date('departure_date');
            $table->date('return_date');
            $table->decimal('estimated_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | completed
            $table->foreignId('approver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status']);
        });

        // Salary advances live in the Payroll module's table so payroll can recover them; add the repayment term.
        if (Schema::hasTable('payroll_salary_advances') && ! Schema::hasColumn('payroll_salary_advances', 'repayment_months')) {
            Schema::table('payroll_salary_advances', function (Blueprint $table) {
                $table->unsignedTinyInteger('repayment_months')->default(1)->after('reason');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_requests');
        Schema::dropIfExists('expense_claims');
        if (Schema::hasColumn('payroll_salary_advances', 'repayment_months')) {
            Schema::table('payroll_salary_advances', fn (Blueprint $t) => $t->dropColumn('repayment_months'));
        }
    }
};
