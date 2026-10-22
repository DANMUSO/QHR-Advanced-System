<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recognition, calibration, Learning & Development and Succession & Talent tables,
 * plus the appraisal workflow (pending → self_review → manager_review → completed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perf_appraisals', function (Blueprint $t) {
            $t->string('status', 20)->default('pending')->change();
            $t->decimal('kpi_weight', 5, 2)->default(70)->change();
            $t->decimal('competency_weight', 5, 2)->default(30)->change();
            $t->string('calibrated_band', 60)->nullable()->after('rating_band');
            $t->foreignId('calibrated_by_user_id')->nullable()->after('calibrated_band')->constrained('users')->nullOnDelete();
            $t->timestamp('calibrated_at')->nullable()->after('calibrated_by_user_id');
            $t->text('calibration_note')->nullable()->after('calibrated_at');
            $t->foreignId('questionnaire_id')->nullable()->after('supervisor_id')->constrained('perf_questionnaires')->nullOnDelete();
        });

        Schema::create('perf_recognitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('awarded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('category', 30); // star_performer | innovation | teamwork | customer_service | leadership
            $t->text('message');
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
        });

        // ---- Learning & Development ---------------------------------------------------------
        Schema::create('ld_courses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('category', 80)->nullable();
            $t->string('provider')->nullable();
            $t->decimal('duration_hours', 6, 1)->nullable();
            $t->string('delivery', 20)->default('classroom'); // classroom | online | blended
            $t->decimal('cost', 14, 2)->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('ld_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained('ld_courses')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 20)->default('enrolled'); // enrolled | in_progress | completed | cancelled
            $t->unsignedTinyInteger('progress')->default(0);
            $t->date('completed_at')->nullable();
            $t->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['course_id', 'employee_id']);
        });

        Schema::create('ld_skills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('category', 80)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });

        Schema::create('ld_employee_skills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('skill_id')->constrained('ld_skills')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('level'); // 1 beginner … 5 expert
            $t->foreignId('assessed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['skill_id', 'employee_id']);
        });

        Schema::create('ld_certifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('issuing_body')->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('credential_id', 100)->nullable();
            $t->timestamps();
        });

        // ---- Succession & Talent -------------------------------------------------------------
        Schema::create('succ_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('position_id')->constrained()->cascadeOnDelete();
            $t->foreignId('incumbent_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('successor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('readiness', 20)->default('ready_1_2_years'); // ready_now | ready_1_2_years | ready_3_5_years | not_ready
            $t->string('risk_level', 10)->default('medium'); // low | medium | high (risk of losing the incumbent)
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('succ_career_paths', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->foreignId('from_position_id')->constrained('positions')->cascadeOnDelete();
            $t->foreignId('to_position_id')->constrained('positions')->cascadeOnDelete();
            $t->unsignedTinyInteger('min_years')->default(2);
            $t->text('requirements')->nullable();
            $t->timestamps();
        });

        Schema::create('succ_talent_pools', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->text('criteria')->nullable();
            $t->timestamps();
        });

        Schema::create('succ_talent_pool_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('talent_pool_id')->constrained('succ_talent_pools')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['talent_pool_id', 'employee_id']);
        });

        Schema::create('succ_potential_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cycle_id')->constrained('perf_cycles')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('potential'); // 1 low, 2 medium, 3 high
            $t->text('note')->nullable();
            $t->foreignId('rated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['cycle_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['succ_potential_ratings', 'succ_talent_pool_members', 'succ_talent_pools', 'succ_career_paths', 'succ_plans',
            'ld_certifications', 'ld_employee_skills', 'ld_skills', 'ld_enrollments', 'ld_courses', 'perf_recognitions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('perf_appraisals', function (Blueprint $t) {
            $t->dropConstrainedForeignId('calibrated_by_user_id');
            $t->dropConstrainedForeignId('questionnaire_id');
            $t->dropColumn(['calibrated_band', 'calibrated_at', 'calibration_note']);
        });
    }
};
