<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requisitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('requisition_number', 30)->unique();
            $t->foreignId('position_id')->constrained()->restrictOnDelete();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('vacancies')->default(1);
            $t->string('type', 20)->default('permanent'); // permanent | fixed_term | casual | internship
            $t->text('justification')->nullable();
            $t->string('status', 20)->default('pending_approval'); // draft | pending_approval | approved | rejected | filled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('recruitment_postings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $t->string('title', 200);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('location', 150)->nullable();
            $t->date('closing_date')->nullable();
            $t->text('description')->nullable();
            $t->text('requirements')->nullable();
            $t->string('status', 20)->default('published'); // draft | published | paused | closed
            $t->string('slug', 220)->unique();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('recruitment_applicants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('posting_id')->constrained('recruitment_postings')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('email', 190);
            $t->string('phone', 30)->nullable();
            $t->string('stage', 20)->default('applied'); // applied | screening | interview | offer | hired | rejected
            $t->unsignedTinyInteger('score')->nullable(); // 0-100
            $t->string('source', 60)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('applied_at')->useCurrent();
            $t->timestamp('hired_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'stage']);
        });

        Schema::create('recruitment_interviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('applicant_id')->constrained('recruitment_applicants')->cascadeOnDelete();
            $t->string('interview_type', 20)->default('panel'); // phone | hr | technical | panel | final
            $t->dateTime('scheduled_at');
            $t->foreignId('interviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('location', 150)->nullable();
            $t->string('status', 20)->default('scheduled'); // scheduled | completed | cancelled | no_show
            $t->unsignedTinyInteger('score')->nullable();
            $t->text('feedback')->nullable();
            $t->timestamps();
        });

        Schema::create('recruitment_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('applicant_id')->constrained('recruitment_applicants')->cascadeOnDelete();
            $t->decimal('offered_salary', 14, 2);
            $t->date('start_date');
            $t->date('expiry_date');
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('sent'); // draft | sent | accepted | declined | expired
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('recruitment_induction_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('category', 20)->default('hr'); // hr | it | finance | manager | security
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('due_days')->default(1); // days after start date
            $t->boolean('is_mandatory')->default(true);
            $t->string('owner_role', 30)->nullable();
            $t->timestamps();
        });

        Schema::create('recruitment_onboarding_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('induction_task_id')->nullable()->constrained('recruitment_induction_tasks')->nullOnDelete();
            $t->string('name', 150);
            $t->string('category', 20)->default('hr');
            $t->date('due_date')->nullable();
            $t->boolean('is_mandatory')->default(true);
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'induction_task_id'], 'onboarding_tasks_employee_task_unique');
        });

        Schema::create('asset_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('asset_name', 150);
            $t->string('asset_tag', 60)->nullable();
            $t->date('assigned_date');
            $t->date('return_date')->nullable();
            $t->string('status', 20)->default('assigned'); // assigned | returned | lost | damaged
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['asset_assignments', 'recruitment_onboarding_tasks', 'recruitment_induction_tasks', 'recruitment_offers',
            'recruitment_interviews', 'recruitment_applicants', 'recruitment_postings', 'recruitment_requisitions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
