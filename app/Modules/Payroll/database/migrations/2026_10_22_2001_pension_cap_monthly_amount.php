<?php

use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allowable pension contribution cap: KES 30,000 a month (Finance Act / Tax Laws (Amendment) Act 2024).
 * Statutory rates are global (statute applies to every company). Rows that only carried the cap get the
 * amount too, so the setup screen and the calculator agree; a missing row is seeded. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_statutory_rates')->where('type', 'pension_cap')
            ->where(fn ($q) => $q->whereNull('amount')->orWhereNull('cap')->orWhere('amount', '<=', 0)->orWhere('cap', '<=', 0))
            ->get(['id', 'amount', 'cap'])
            ->each(function ($r) {
                $value = (float) ($r->cap ?: $r->amount ?: 30000);
                DB::table('payroll_statutory_rates')->where('id', $r->id)->update(['amount' => $value, 'cap' => $value, 'updated_at' => now()]);
            });

        PayrollDefaults::seedStatutory(); // inserts the pension cap row (and any other default) if missing
    }

    public function down(): void
    {
        // Data fix only: nothing to undo.
    }
};
