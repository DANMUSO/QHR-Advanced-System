<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service requests raised from My Portal and decided by HR:
 * sensitive profile changes (statutory numbers, next of kin, pay details), resignations and letter requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profile_change_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->json('changes');              // requested values: statutory fields, next_of_kin rows, payment details
            $t->json('previous')->nullable(); // values on file when requested (for the HR review)
            $t->string('note', 1000)->nullable();
            $t->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 1000)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::create('employee_resignations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('notice_date');
            $t->date('last_working_day');
            $t->text('reason');
            $t->string('letter_path')->nullable();
            $t->string('letter_name')->nullable();
            $t->string('status', 20)->default('pending'); // pending | processed | withdrawn | declined
            $t->foreignId('exit_id')->nullable()->constrained('employee_exits')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 1000)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::create('employee_letter_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('template', 40);       // introduction | employment_confirmation | no_objection
            $t->string('addressed_to')->nullable();
            $t->text('purpose');
            $t->string('status', 20)->default('pending'); // pending | issued | declined | cancelled
            $t->foreignId('letter_id')->nullable()->constrained('employee_letters')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 1000)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_letter_requests');
        Schema::dropIfExists('employee_resignations');
        Schema::dropIfExists('employee_profile_change_requests');
    }
};
