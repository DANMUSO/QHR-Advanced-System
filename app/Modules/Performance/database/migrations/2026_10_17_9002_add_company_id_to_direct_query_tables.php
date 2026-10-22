<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds company_id to tables that are queried directly (not only through a parent)
 * and therefore need the BelongsToCompany global scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        // perf_goal_events — back-fill from perf_goals
        Schema::table('perf_goal_events', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE perf_goal_events SET company_id = (SELECT g.company_id FROM perf_goals g WHERE g.id = perf_goal_events.goal_id)');
        Schema::table('perf_goal_events', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });

        // perf_pip_checkins — back-fill from perf_pips
        Schema::table('perf_pip_checkins', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE perf_pip_checkins SET company_id = (SELECT p.company_id FROM perf_pips p WHERE p.id = perf_pip_checkins.pip_id)');
        Schema::table('perf_pip_checkins', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });

        // ld_employee_skills — back-fill from employees
        Schema::table('ld_employee_skills', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE ld_employee_skills SET company_id = (SELECT e.company_id FROM employees e WHERE e.id = ld_employee_skills.employee_id)');
        Schema::table('ld_employee_skills', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });

        // succ_potential_ratings — back-fill from employees
        Schema::table('succ_potential_ratings', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE succ_potential_ratings SET company_id = (SELECT e.company_id FROM employees e WHERE e.id = succ_potential_ratings.employee_id)');
        Schema::table('succ_potential_ratings', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });

        // company_document_acknowledgements — back-fill from company_documents
        Schema::table('company_document_acknowledgements', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE company_document_acknowledgements SET company_id = (SELECT d.company_id FROM company_documents d WHERE d.id = company_document_acknowledgements.company_document_id)');
        Schema::table('company_document_acknowledgements', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        foreach (['company_document_acknowledgements', 'succ_potential_ratings', 'ld_employee_skills', 'perf_pip_checkins', 'perf_goal_events'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('company_id');
            });
        }
    }
};
