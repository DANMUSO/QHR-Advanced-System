<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE payroll_adjustments SET company_id = (SELECT r.company_id FROM payroll_runs r WHERE r.id = payroll_adjustments.payroll_run_id)');

        Schema::table('payroll_adjustments', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
        });
    }
};
