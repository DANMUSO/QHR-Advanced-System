<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds company_id to tables that use BelongsToCompany but were missing the column,
 * and adds company_id to succ_talent_pool_members for multi-tenant safety.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ld_enrollments: Enrollment model uses BelongsToCompany — needs company_id.
        Schema::table('ld_enrollments', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        // Back-fill from the parent course.
        DB::statement('UPDATE ld_enrollments SET company_id = (SELECT c.company_id FROM ld_courses c WHERE c.id = ld_enrollments.course_id)');
        Schema::table('ld_enrollments', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });

        // succ_talent_pool_members: pivot needs company_id for direct tenant-scoped queries.
        Schema::table('succ_talent_pool_members', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE succ_talent_pool_members SET company_id = (SELECT p.company_id FROM succ_talent_pools p WHERE p.id = succ_talent_pool_members.talent_pool_id)');
        Schema::table('succ_talent_pool_members', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });

        // engagement_replies: add company_id so replies can be tenant-scoped directly.
        Schema::table('engagement_replies', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE engagement_replies SET company_id = (SELECT c.company_id FROM engagement_comments c WHERE c.id = engagement_replies.comment_id)');
        Schema::table('engagement_replies', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('engagement_replies', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
        });
        Schema::table('succ_talent_pool_members', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
        });
        Schema::table('ld_enrollments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
        });
    }
};
