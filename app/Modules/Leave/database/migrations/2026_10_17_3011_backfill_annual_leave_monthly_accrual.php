<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix (idempotent): monthly-accruing leave types created without a monthly accrual
 * (e.g. Annual Leave) get days_per_year / 12, else the company setting, else 1.75.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('companies')->pluck('settings', 'id');
        DB::table('leave_types')->where('accrues_monthly', true)->whereNull('monthly_accrual')->orderBy('id')
            ->get(['id', 'company_id', 'days_per_year'])
            ->each(function ($t) use ($companies) {
                $settings = json_decode((string) ($companies[$t->company_id] ?? ''), true) ?: [];
                $rate = $t->days_per_year > 0 ? round($t->days_per_year / 12, 2)
                    : (is_numeric($settings['monthly_accrual'] ?? null) ? (float) $settings['monthly_accrual'] : 1.75);
                DB::table('leave_types')->where('id', $t->id)->whereNull('monthly_accrual')->update(['monthly_accrual' => $rate, 'updated_at' => now()]);
            });
    }

    public function down(): void
    {
        // Data backfill only; nothing to undo.
    }
};
