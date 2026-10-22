<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable approval chain on leave requests (snapshot of the company's Workflow steps, current step index,
 * the role a role-step waits on, and every step decision), and leave_types.customised: a type HR has edited
 * keeps its own entitlement / accrual instead of following the company leave settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_requests', 'approval_step')) {
                $table->unsignedTinyInteger('approval_step')->nullable()->after('supervisor_id'); // null = built-in single-supervisor flow
                $table->json('approval_chain')->nullable()->after('approval_step');
                $table->string('approver_role', 100)->nullable()->after('approval_chain');
                $table->json('approval_history')->nullable()->after('approver_role');
            }
        });

        Schema::table('leave_types', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_types', 'customised')) {
                $table->boolean('customised')->default(false)->after('active');
            }
        });

        // Types already edited through the UI (logged as "Leave type updated") keep their own values.
        if (Schema::hasTable('activity_log')) {
            $edited = DB::table('activity_log')->where('subject_type', 'App\Modules\Leave\Models\LeaveType')
                ->where('description', 'Leave type updated')->pluck('subject_id')->unique()->all();
            if ($edited) {
                DB::table('leave_types')->whereIn('id', $edited)->update(['customised' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['approval_step', 'approval_chain', 'approver_role', 'approval_history']);
        });
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('customised');
        });
    }
};
