<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Overtime settled by an approved payroll run: status "paid", the run that paid it and when. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_overtime', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_overtime', 'payroll_run_id')) {
                // No foreign key: Attendance must not depend on the Payroll module's tables.
                $table->unsignedBigInteger('payroll_run_id')->nullable()->after('reason')->index();
                $table->timestamp('paid_at')->nullable()->after('payroll_run_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_overtime', function (Blueprint $table) {
            $table->dropIndex(['payroll_run_id']);
            $table->dropColumn(['payroll_run_id', 'paid_at']);
        });
    }
};
