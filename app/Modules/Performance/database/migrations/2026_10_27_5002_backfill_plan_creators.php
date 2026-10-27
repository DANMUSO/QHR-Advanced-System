<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA #32: plans created by an admin without an employee record showed a blank "Created by". Backfill the creating
 * user from the activity log ("created" event) and the employee from that user, where known. Idempotent; data-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('perf_plans')->whereNull('created_by_user_id')->pluck('id') as $id) {
            $causer = DB::table('activity_log')->where('subject_type', 'App\\Modules\\Performance\\Models\\Plan')
                ->where('subject_id', $id)->where('event', 'created')->where('causer_type', 'App\\Models\\User')->value('causer_id');
            if ($causer) {
                DB::table('perf_plans')->where('id', $id)->update(['created_by_user_id' => $causer]);
            }
        }
        foreach (DB::table('perf_plans')->whereNull('created_by')->whereNotNull('created_by_user_id')->get(['id', 'created_by_user_id']) as $plan) {
            $employee = DB::table('employees')->where('user_id', $plan->created_by_user_id)->whereNull('deleted_at')->value('id');
            if ($employee) {
                DB::table('perf_plans')->where('id', $plan->id)->update(['created_by' => $employee]);
            }
        }
    }

    public function down(): void
    {
        // Data backfill; not reversed.
    }
};
