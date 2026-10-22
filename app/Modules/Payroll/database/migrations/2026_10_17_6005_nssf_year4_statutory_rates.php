<?php

use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NSSF Act 2013 phase-in, Year 4 (from February 2026): LEL 9,000 / UEL 108,000, 6% employee + 6% employer per tier.
 * The Year 3 row (8,000 / 72,000) is closed on 2026-01-31 so historical periods keep their rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_statutory_rates')->where('type', 'nssf')->where('lower_limit', 8000)->where('upper_limit', 72000)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', '2026-01-31'))
            ->update(['effective_to' => '2026-01-31', 'updated_at' => now()]);

        PayrollDefaults::seedStatutory(); // inserts the Year 4 row if missing (idempotent)
    }

    public function down(): void
    {
        DB::table('payroll_statutory_rates')->where('type', 'nssf')->whereDate('effective_from', '2026-02-01')->delete();
        DB::table('payroll_statutory_rates')->where('type', 'nssf')->where('lower_limit', 8000)->update(['effective_to' => null]);
    }
};
