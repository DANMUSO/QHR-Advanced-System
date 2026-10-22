<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data fix: resignations used to stay "pending" until a scheduled exit's date arrived. Close every pending
 * resignation whose employee already has a scheduled or completed exit, linking it to that exit. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_resignations') || ! Schema::hasTable('employee_exits')) {
            return;
        }

        DB::table('employee_resignations')->where('status', 'pending')->orderBy('id')->get(['id', 'employee_id'])
            ->each(function ($r) {
                $exit = DB::table('employee_exits')->where('employee_id', $r->employee_id)
                    ->whereIn('status', ['scheduled', 'completed'])->orderByDesc('id')->first(['id', 'processed_by']);
                if ($exit) {
                    DB::table('employee_resignations')->where('id', $r->id)->update([
                        'status' => 'processed', 'exit_id' => $exit->id, 'decided_at' => now(), 'decided_by' => $exit->processed_by,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Data fix only.
    }
};
