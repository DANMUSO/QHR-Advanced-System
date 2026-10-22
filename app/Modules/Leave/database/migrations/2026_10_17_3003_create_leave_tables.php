<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 30);
            $table->string('description')->nullable();
            $table->decimal('days_per_year', 6, 2)->nullable();      // null = unlimited (unpaid) or drawn from another type
            $table->decimal('opening_days', 6, 2)->nullable();       // first-year entitlement for a new hire (null = days_per_year)
            $table->string('counting', 10)->default('working');      // working | calendar
            $table->boolean('requires_document')->default(false);
            $table->string('gender', 10)->nullable();                // null | male | female
            $table->boolean('accrues_monthly')->default(false);
            $table->decimal('monthly_accrual', 5, 2)->nullable();    // null = company setting monthly_accrual
            $table->boolean('carry_forward')->default(false);
            $table->decimal('carry_forward_cap', 6, 2)->nullable();  // null = company setting carry_forward_cap
            $table->string('paid', 10)->default('full');             // full | half | unpaid
            $table->boolean('has_balance')->default(true);           // false = unlimited (no balance tracked)
            $table->foreignId('balance_type_id')->nullable()->constrained('leave_types')->nullOnDelete(); // e.g. Hourly draws from Annual
            $table->boolean('allow_half_day')->default(false);
            $table->boolean('allow_hours')->default(false);
            $table->unsignedSmallInteger('max_consecutive')->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(50);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled', 8, 2)->default(0);
            $table->decimal('accrued', 8, 2)->default(0);
            $table->decimal('taken', 8, 2)->default(0);
            $table->decimal('adjusted', 8, 2)->default(0);
            $table->decimal('carried_forward', 8, 2)->default(0);
            $table->decimal('forfeited', 8, 2)->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'year']);
            $table->index(['company_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('half_day')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('hours', 5, 2)->nullable();
            $table->decimal('days', 6, 2);
            $table->string('status', 30)->default('pending_supervisor');
            // Handover
            $table->foreignId('handover1_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('handover1_status', 20)->nullable();   // pending | approved | rejected
            $table->string('handover1_reason')->nullable();
            $table->timestamp('handover1_at')->nullable();
            $table->foreignId('handover2_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('handover2_status', 20)->nullable();
            $table->string('handover2_reason')->nullable();
            $table->timestamp('handover2_at')->nullable();
            $table->text('duties')->nullable();
            $table->text('comment')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            // Supervisor decision
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->boolean('amended')->default(false);
            $table->date('original_start_date')->nullable();
            $table->date('original_end_date')->nullable();
            $table->decimal('original_days', 6, 2)->nullable();
            // Ledger link
            $table->decimal('deducted_days', 6, 2)->default(0);
            $table->unsignedSmallInteger('balance_year')->nullable();
            // Recall / cancel
            $table->date('recall_date')->nullable();
            $table->decimal('recalled_days', 6, 2)->nullable();
            $table->foreignId('recalled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recalled_at')->nullable();
            $table->string('recall_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'start_date']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('leave_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_balance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // opening | accrual | deduction | recall_credit | adjustment | carry_forward | forfeiture
            $table->decimal('days', 8, 2); // signed effect on the available balance
            $table->string('reason', 500)->nullable();
            $table->string('reference', 60)->nullable(); // idempotency key for scheduled postings, e.g. accrual:2026-10
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_date');
            $table->timestamps();
            $table->index(['employee_id', 'leave_type_id']);
            $table->unique(['leave_balance_id', 'reference']);
        });

        Schema::create('leave_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job', 30);      // accrual | carry_forward | reminders
            $table->string('period', 20);   // 2026-10 | 2026 | 2026-10-16
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('records')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();
            $table->unique(['job', 'period', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_job_runs');
        Schema::dropIfExists('leave_transactions');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};
