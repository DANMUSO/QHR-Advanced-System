<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Setup ---------------------------------------------------------------------------
        Schema::create('perf_cycles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 20)->default('draft'); // draft | active | closed
            $t->decimal('kpi_weight', 5, 2)->default(70);
            $t->decimal('competency_weight', 5, 2)->default(30);
            $t->decimal('max_score_pct', 6, 2)->default(120);
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::create('perf_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 50);
            $t->boolean('is_percentage')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });

        Schema::create('perf_frequencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 50);
            $t->unsignedSmallInteger('days');
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });

        Schema::create('perf_rating_scales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('value');
            $t->string('label', 60);
            $t->string('description')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'value']);
        });

        Schema::create('perf_rating_bands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->decimal('min_score', 6, 2);
            $t->string('color', 20)->default('slate');
            $t->timestamps();
        });

        Schema::create('perf_question_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('perf_question_categories')->cascadeOnDelete();
            $t->string('name');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('perf_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->constrained('perf_question_categories')->cascadeOnDelete();
            $t->text('question');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ---- Scorecards ----------------------------------------------------------------------
        Schema::create('perf_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cycle_id')->constrained('perf_cycles')->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->date('start_date')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('perf_focus_areas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained('perf_plans')->cascadeOnDelete();
            $t->string('name');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('perf_objectives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained('perf_plans')->cascadeOnDelete();
            $t->foreignId('focus_area_id')->constrained('perf_focus_areas')->cascadeOnDelete();
            $t->string('name');
            $t->decimal('weight', 5, 2)->default(0);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('perf_kpis', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained('perf_plans')->cascadeOnDelete();
            $t->foreignId('objective_id')->constrained('perf_objectives')->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('type', 20)->default('quantitative'); // quantitative | qualitative
            $t->string('kpi_type', 20)->default('absolute');  // percentage | absolute
            $t->foreignId('unit_id')->nullable()->constrained('perf_units')->nullOnDelete();
            $t->decimal('target', 14, 2)->nullable();
            $t->foreignId('frequency_id')->nullable()->constrained('perf_frequencies')->nullOnDelete();
            $t->boolean('is_recurring')->default(false);
            $t->date('period_start')->nullable();
            $t->date('due_date');
            $t->foreignId('recurred_from_id')->nullable()->constrained('perf_kpis')->nullOnDelete();
            $t->timestamp('rolled_over_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $t->timestamps();
            $t->index(['is_recurring', 'due_date']);
        });

        Schema::create('perf_kpi_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kpi_id')->constrained('perf_kpis')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('perf_questions')->cascadeOnDelete();
            $t->unique(['kpi_id', 'question_id']);
        });

        // ---- Per-employee goals ---------------------------------------------------------------
        Schema::create('perf_goals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cycle_id')->constrained('perf_cycles')->cascadeOnDelete();
            $t->foreignId('kpi_id')->constrained('perf_kpis')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_goal_id')->nullable()->constrained('perf_goals')->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('approver_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('mode', 20)->default('cascade'); // cascade | self
            $t->string('split', 20)->nullable();         // distributed | equal
            $t->decimal('target', 14, 2)->nullable();
            $t->date('due_date');
            $t->string('status', 20)->default('cascaded'); // cascaded | in_progress | under_review | submitted | approved
            $t->decimal('achieved', 14, 2)->nullable();
            $t->decimal('achievement_pct', 7, 2)->default(0);
            $t->decimal('score', 7, 2)->default(0);
            $t->text('employee_comment')->nullable();
            $t->text('supervisor_comment')->nullable();
            $t->text('review_reason')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->string('evidence_path')->nullable();
            $t->string('evidence_name')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
            $t->index(['approver_id', 'status']);
            $t->index(['status', 'due_date']);
        });

        Schema::create('perf_goal_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goal_id')->constrained('perf_goals')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('perf_questions')->cascadeOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->unique(['goal_id', 'question_id']);
        });

        Schema::create('perf_goal_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goal_id')->constrained('perf_goals')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 40);
            $t->text('comment')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['goal_id', 'created_at']);
        });

        Schema::create('perf_goal_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goal_id')->constrained('perf_goals')->cascadeOnDelete();
            $t->unsignedTinyInteger('days_before');
            $t->date('due_date');
            $t->timestamp('sent_at')->useCurrent();
            $t->unique(['goal_id', 'days_before', 'due_date']);
        });

        // ---- Competency / 360 -----------------------------------------------------------------
        Schema::create('perf_questionnaires', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cycle_id')->constrained('perf_cycles')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('title');
            $t->date('due_date');
            $t->foreignId('frequency_id')->nullable()->constrained('perf_frequencies')->nullOnDelete();
            $t->string('status', 20)->default('pending'); // pending | self_submitted | supervisor_submitted | completed
            $t->timestamp('self_submitted_at')->nullable();
            $t->timestamp('supervisor_submitted_at')->nullable();
            $t->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('perf_questionnaire_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('questionnaire_id')->constrained('perf_questionnaires')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('perf_questions')->cascadeOnDelete();
            $t->unique(['questionnaire_id', 'question_id'], 'perf_qq_unique');
        });

        Schema::create('perf_questionnaire_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('questionnaire_id')->constrained('perf_questionnaires')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('perf_questions')->cascadeOnDelete();
            $t->string('rater_type', 20); // self | supervisor
            $t->unsignedTinyInteger('rating');
            $t->timestamps();
            $t->unique(['questionnaire_id', 'question_id', 'rater_type'], 'perf_qr_unique');
        });

        Schema::create('perf_questionnaire_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('questionnaire_id')->constrained('perf_questionnaires')->cascadeOnDelete();
            $t->foreignId('category_id')->constrained('perf_question_categories')->cascadeOnDelete();
            $t->string('rater_type', 20);
            $t->text('comment');
            $t->timestamps();
            $t->unique(['questionnaire_id', 'category_id', 'rater_type'], 'perf_qc_unique');
        });

        // ---- Appraisal result ----------------------------------------------------------------
        Schema::create('perf_appraisals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cycle_id')->constrained('perf_cycles')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->decimal('kpi_score', 7, 2)->nullable();
            $t->decimal('competency_score', 7, 2)->nullable();
            $t->decimal('kpi_weight', 5, 2);
            $t->decimal('competency_weight', 5, 2);
            $t->decimal('final_score', 7, 2)->nullable();
            $t->string('rating_band', 60)->nullable();
            $t->text('supervisor_comment')->nullable();
            $t->string('status', 20)->default('finalized'); // finalized | acknowledged
            $t->timestamp('finalized_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('employee_comment')->nullable();
            $t->timestamps();
            $t->unique(['cycle_id', 'employee_id']);
        });

        // ---- Performance Boost Program -------------------------------------------------------
        Schema::create('perf_discussion_points', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('goal_id')->nullable()->constrained('perf_goals')->nullOnDelete();
            $t->string('activity');
            $t->text('comment')->nullable();
            $t->date('deadline')->nullable();
            $t->string('status', 20)->default('open'); // open | completed | extended | escalated
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('perf_pips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->text('concern');
            $t->text('targets');
            $t->text('action_plan');
            $t->text('resources')->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('review_frequency', 20)->default('monthly'); // weekly | biweekly | monthly
            $t->string('status', 20)->default('active'); // active | extended | completed | escalated
            $t->string('outcome', 30)->nullable(); // successful | unsuccessful | escalated
            $t->text('outcome_comment')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('perf_coaching_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('goal_id')->nullable()->constrained('perf_goals')->nullOnDelete();
            $t->date('session_date');
            $t->string('topic');
            $t->text('goal')->nullable();
            $t->text('reality')->nullable();
            $t->text('options')->nullable();
            $t->text('way_forward')->nullable();
            $t->text('staff_comment')->nullable();
            $t->text('supervisor_comment')->nullable();
            $t->text('hr_comment')->nullable();
            $t->date('next_meeting_date')->nullable();
            $t->string('outcome', 20)->nullable(); // completed | extended | escalated
            $t->foreignId('pip_id')->nullable()->constrained('perf_pips')->nullOnDelete();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('perf_pip_checkins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pip_id')->constrained('perf_pips')->cascadeOnDelete();
            $t->date('meeting_date');
            $t->unsignedTinyInteger('progress_rating');
            $t->text('comment')->nullable();
            $t->string('recommendation', 20); // continue | extend | complete | escalate
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'perf_pip_checkins', 'perf_coaching_sessions', 'perf_pips', 'perf_discussion_points', 'perf_appraisals',
            'perf_questionnaire_comments', 'perf_questionnaire_responses', 'perf_questionnaire_questions', 'perf_questionnaires',
            'perf_goal_reminders', 'perf_goal_events', 'perf_goal_ratings', 'perf_goals', 'perf_kpi_questions', 'perf_kpis',
            'perf_objectives', 'perf_focus_areas', 'perf_plans', 'perf_questions', 'perf_question_categories',
            'perf_rating_bands', 'perf_rating_scales', 'perf_frequencies', 'perf_units', 'perf_cycles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
