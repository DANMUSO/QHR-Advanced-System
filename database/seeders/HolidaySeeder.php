<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/** Kenyan public holidays (global — company_id null). Movable feasts are seeded per year. */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $fixed = [
            ['New Year\'s Day', '2026-01-01'],
            ['Labour Day', '2026-05-01'],
            ['Madaraka Day', '2026-06-01'],
            ['Mazingira Day', '2026-10-10'],
            ['Mashujaa Day', '2026-10-20'],
            ['Jamhuri Day', '2026-12-12'],
            ['Christmas Day', '2026-12-25'],
            ['Boxing Day', '2026-12-26'],
        ];
        foreach ($fixed as [$name, $date]) {
            Holiday::updateOrCreate(['company_id' => null, 'name' => $name], ['date' => $date, 'recurring' => true]);
        }

        $movable = [
            ['Good Friday', '2026-04-03'], ['Easter Monday', '2026-04-06'],
            ['Good Friday', '2027-03-26'], ['Easter Monday', '2027-03-29'],
        ];
        foreach ($movable as [$name, $date]) {
            Holiday::updateOrCreate(['company_id' => null, 'name' => $name, 'date' => $date], ['recurring' => false]);
        }
    }
}
