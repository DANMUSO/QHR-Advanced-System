<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * perf_goals.approval_step: index into the company's kpi_review approval chain (0 when no chain is configured).
 * perf_recognitions.title: short headline for peer recognition (self-service contract).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('perf_goals', 'approval_step')) {
            Schema::table('perf_goals', function (Blueprint $t) {
                $t->unsignedTinyInteger('approval_step')->default(0)->after('approver_id');
            });
        }
        if (! Schema::hasColumn('perf_recognitions', 'title')) {
            Schema::table('perf_recognitions', function (Blueprint $t) {
                $t->string('title', 150)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        Schema::table('perf_recognitions', fn (Blueprint $t) => $t->dropColumn('title'));
        Schema::table('perf_goals', fn (Blueprint $t) => $t->dropColumn('approval_step'));
    }
};
