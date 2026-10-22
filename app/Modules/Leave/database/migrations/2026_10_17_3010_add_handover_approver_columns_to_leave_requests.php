<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalated answerer for each handover slot. NULL = the handover colleague answers themselves; otherwise the
 * colleague had no usable login and the request was routed up their reporting line (ApproverResolver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_requests', 'handover1_approver_id')) {
                $table->foreignId('handover1_approver_id')->nullable()->after('handover1_status')->constrained('employees')->nullOnDelete();
            }
            if (! Schema::hasColumn('leave_requests', 'handover2_approver_id')) {
                $table->foreignId('handover2_approver_id')->nullable()->after('handover2_status')->constrained('employees')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            foreach (['handover1_approver_id', 'handover2_approver_id'] as $col) {
                if (Schema::hasColumn('leave_requests', $col)) {
                    $table->dropConstrainedForeignId($col);
                }
            }
        });
    }
};
