<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review stages: the chain (configured Workflow 'probation_end', else Primary → Secondary → HOO) is snapshotted on the
 * review; stage_index points at the stage awaiting a decision and stage_log keeps each stage's comment or skip note.
 * Status becomes a plain string: pending | in_review | hr_review | confirmed | extended | terminated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('probation_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('probation_reviews', 'stage_index')) {
                $table->unsignedSmallInteger('stage_index')->default(0);
                $table->json('stages')->nullable();
                $table->json('stage_log')->nullable();
                $table->unsignedTinyInteger('cycle')->default(1);
            }
        });
        Schema::table('probation_reviews', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });

        // Legacy fixed-chain statuses → stage pointer.
        foreach (['primary_done' => [1, 'in_review'], 'secondary_done' => [2, 'in_review'], 'hoo_done' => [3, 'hr_review'], 'hr_done' => [3, 'hr_review']] as $old => [$index, $status]) {
            DB::table('probation_reviews')->where('status', $old)->update(['status' => $status, 'stage_index' => $index]);
        }
    }

    public function down(): void
    {
        Schema::table('probation_reviews', function (Blueprint $table) {
            $table->dropColumn(['stage_index', 'stages', 'stage_log', 'cycle']);
        });
    }
};
