<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idd-ul-Fitr is a public holiday under Kenya's Public Holidays Act but was never seeded. It is moon-dependent, so
 * the expected dates are seeded as national (company_id null) holidays; HR edits them when the date is gazetted.
 */
return new class extends Migration
{
    private const DATES = ['2026-03-20', '2027-03-10'];

    public function up(): void
    {
        foreach (self::DATES as $date) {
            $exists = DB::table('holidays')->whereNull('company_id')->where('name', 'Idd-ul-Fitr')->whereDate('date', $date)->exists();
            if (! $exists) {
                DB::table('holidays')->insert(['company_id' => null, 'name' => 'Idd-ul-Fitr', 'date' => $date, 'recurring' => false,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('holidays')->whereNull('company_id')->where('name', 'Idd-ul-Fitr')->whereIn('date', self::DATES)->delete();
    }
};
