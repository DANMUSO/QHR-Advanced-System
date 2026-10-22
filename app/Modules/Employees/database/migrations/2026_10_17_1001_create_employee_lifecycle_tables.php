<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->enum('contract_type', ['permanent', 'fixed_term', 'casual', 'probation', 'internship']);
            $table->string('job_title')->nullable();          // snapshot at signing
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('gross_salary', 14, 2)->nullable(); // agreed contract term (payroll keeps the live pay)
            $table->enum('status', ['draft', 'active', 'expired', 'terminated', 'superseded'])->default('active');
            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();           // generated PDF (local disk)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'status', 'end_date']);
        });

        Schema::create('employee_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('template', 60);
            $table->string('reference', 40);
            $table->string('subject');
            $table->longText('body');
            $table->date('effective_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'reference']);
        });

        Schema::create('employee_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('new_employee_id')->nullable()->constrained('employees')->nullOnDelete(); // company transfers
            $table->enum('type', ['transfer', 'promotion', 'demotion', 'redeployment', 'company_transfer']);
            $table->foreignId('from_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('to_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('from_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('from_level_id')->nullable()->constrained('employment_levels')->nullOnDelete();
            $table->foreignId('to_level_id')->nullable()->constrained('employment_levels')->nullOnDelete();
            $table->foreignId('from_supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('completed');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status', 'effective_date']);
        });

        Schema::create('employee_kyc_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('check_type', ['identity', 'background']);
            $table->enum('status', ['pending', 'verified', 'failed']);
            $table->string('provider', 60)->default('internal');
            $table->string('reference', 100)->nullable();
            $table->json('result')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'check_type']);
        });

        Schema::table('employee_exits', function (Blueprint $table) {
            $table->date('notice_date')->nullable()->after('exit_date');
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('completed')->after('reason');
            $table->enum('clearance_status', ['pending', 'in_progress', 'cleared'])->default('pending')->after('status');
            $table->json('clearance')->nullable()->after('clearance_status');
            $table->text('exit_interview')->nullable()->after('comments');
            $table->foreignId('successor_supervisor_id')->nullable()->after('exit_interview')->constrained('employees')->nullOnDelete();
            $table->json('reassignments')->nullable()->after('successor_supervisor_id');
            $table->timestamp('completed_at')->nullable()->after('reassignments');
        });
    }

    public function down(): void
    {
        Schema::table('employee_exits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('successor_supervisor_id');
            $table->dropColumn(['notice_date', 'status', 'clearance_status', 'clearance', 'exit_interview', 'reassignments', 'completed_at']);
        });
        Schema::dropIfExists('employee_kyc_checks');
        Schema::dropIfExists('employee_transfers');
        Schema::dropIfExists('employee_letters');
        Schema::dropIfExists('employee_contracts');
    }
};
