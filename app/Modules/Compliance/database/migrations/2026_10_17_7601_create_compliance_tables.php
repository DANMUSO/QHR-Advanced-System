<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comp_disciplinary_cases')) {
            Schema::create('comp_disciplinary_cases', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->string('case_number', 30)->unique();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->string('offence');
                $t->text('description')->nullable();
                $t->date('reported_date');
                $t->date('hearing_date')->nullable();
                $t->string('outcome', 20)->nullable(); // warning | final_warning | suspension | dismissal | exonerated
                $t->string('status', 30)->default('open'); // open | under_investigation | hearing_scheduled | closed | appealed
                $t->text('outcome_notes')->nullable();
                $t->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('comp_grievances')) {
            Schema::create('comp_grievances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->string('reference', 30)->unique();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->string('category', 30)->default('workplace');
                $t->text('description');
                $t->date('filed_date');
                $t->foreignId('assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $t->string('status', 20)->default('open'); // open | investigating | mediation | resolved | escalated
                $t->text('resolution')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('comp_warnings')) {
            Schema::create('comp_warnings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->foreignId('disciplinary_case_id')->nullable()->constrained('comp_disciplinary_cases')->nullOnDelete();
                $t->string('level', 10)->default('written'); // verbal | written | final
                $t->text('reason');
                $t->date('issued_date');
                $t->date('expiry_date')->nullable();
                $t->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('comp_hs_hazards')) {
            Schema::create('comp_hs_hazards', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->string('title');
                $t->string('category', 30)->default('hazard'); // hazard | near_miss | accident | fire_safety | ergonomics
                $t->string('risk_level', 10)->default('medium'); // low | medium | high | critical
                $t->text('description')->nullable();
                $t->date('reported_date');
                $t->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('status', 20)->default('open'); // open | in_progress | closed
                $t->text('action_taken')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('comp_incidents')) {
            Schema::create('comp_incidents', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->string('incident_number', 30)->unique();
                $t->string('title');
                $t->string('type', 30)->default('accident'); // accident | near_miss | property_damage | security
                $t->string('severity', 10)->default('minor'); // minor | moderate | serious | critical
                $t->string('location')->nullable();
                $t->dateTime('incident_date');
                $t->text('description')->nullable();
                $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete(); // person affected
                $t->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('status', 20)->default('open'); // open | investigating | closed
                $t->text('corrective_action')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('comp_data_requests')) {
            Schema::create('comp_data_requests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->string('reference', 30)->unique();
                $t->string('requester_name');
                $t->string('requester_email')->nullable();
                $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
                $t->string('type', 20)->default('access'); // access | erasure | rectification | portability | objection
                $t->text('description')->nullable();
                $t->date('submitted_date');
                $t->date('due_date');
                $t->string('status', 20)->default('pending'); // pending | in_progress | completed | rejected
                $t->text('response_notes')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['comp_data_requests', 'comp_incidents', 'comp_hs_hazards', 'comp_warnings', 'comp_grievances', 'comp_disciplinary_cases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
