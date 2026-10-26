<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Transport and airtime allowances paid in cash are taxable employment income in Kenya (Income Tax Act s.5).
 * The original demo seed created them as non-taxable in the first two companies, which under-deducted PAYE
 * (QA: ~KES 1,500 per employee per month in Rift Valley). Fix only rows nobody has edited since they were created,
 * so a deliberate company choice is never overwritten. Locked runs keep their figures; recalculate open runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_components')
            ->whereIn('code', ['TRANSPORT', 'AIRTIME'])
            ->where('taxable', false)
            ->whereColumn('updated_at', 'created_at')
            ->update(['taxable' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible: restoring a tax-free cash allowance would reintroduce the PAYE under-deduction.
    }
};
