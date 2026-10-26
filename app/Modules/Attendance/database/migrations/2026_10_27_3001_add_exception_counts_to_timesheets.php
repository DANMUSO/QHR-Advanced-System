<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Exceptions captured when a timesheet is submitted, so the approver sees them (missing clock-outs, absences). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_timesheets', function (Blueprint $t) {
            $t->unsignedTinyInteger('incomplete_days')->default(0)->after('overtime_minutes');
            $t->unsignedTinyInteger('absent_days')->default(0)->after('incomplete_days');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_timesheets', function (Blueprint $t) {
            $t->dropColumn(['incomplete_days', 'absent_days']);
        });
    }
};
