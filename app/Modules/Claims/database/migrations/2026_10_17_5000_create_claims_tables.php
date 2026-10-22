<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One Medical Approver (HR) and one Finance Payment Person per company.
        Schema::create('claim_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('medical_approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('finance_approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Per-employee overrides of the company default limits (null = use company default).
        Schema::create('claim_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('annual_limit', 14, 2)->nullable();
            $table->decimal('monthly_limit', 14, 2)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('medical_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('claimant_type', 20)->default('self'); // self | spouse | child | parent | other
            $table->foreignId('dependant_id')->nullable()->constrained('employee_dependants')->nullOnDelete();
            $table->string('claimant_name', 150);
            $table->date('claim_date');
            $table->string('provider_name', 200);
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 14, 2);
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->string('status', 20)->default('submitted')->index();
            $table->foreignId('routed_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('hr_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_decided_at')->nullable();
            $table->text('hr_comment')->nullable();
            $table->foreignId('finance_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finance_decided_at')->nullable();
            $table->text('finance_comment')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_batch', 40)->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'claim_date']);
        });

        Schema::create('medical_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_claim_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('description', 200)->nullable();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::create('medical_claim_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_claim_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Workflow timeline (audit trail per claim).
        Schema::create('medical_claim_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Year-open runs (one row per company and year) so the Jan 1 job is idempotent.
        Schema::create('claim_year_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->json('summary')->nullable();
            $table->timestamp('ran_at');
            $table->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_year_runs');
        Schema::dropIfExists('medical_claim_events');
        Schema::dropIfExists('medical_claim_receipts');
        Schema::dropIfExists('medical_claim_items');
        Schema::dropIfExists('medical_claims');
        Schema::dropIfExists('claim_limits');
        Schema::dropIfExists('claim_approvers');
    }
};
