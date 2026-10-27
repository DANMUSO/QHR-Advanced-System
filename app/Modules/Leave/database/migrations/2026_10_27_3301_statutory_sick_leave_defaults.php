<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Employment Act s.30: after two months' service, 7 days' sick leave on full pay, then 7 on half pay, per 12 months.
 * The catalogue defaulted to 15 + 15. Companies that never customised the types get the statutory default; balances
 * already issued for the current year keep their figures (a company may of course be more generous by editing the type).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')->whereIn('code', ['SICK_FULL', 'SICK_HALF'])->where('days_per_year', 15)
            ->where(fn ($q) => $q->where('customised', false)->orWhereNull('customised'))
            ->update(['days_per_year' => 7, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversed: 15 + 15 was a seeding default, not a company decision.
    }
};
