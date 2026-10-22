<?php

namespace App\Modules\Employees\Database;

use Illuminate\Database\Seeder;

/**
 * Employee module demo data is handled by the main DemoSeeder.
 * This stub exists because module.php references it.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Employee records are created by Database\Seeders\DemoSeeder.
        // Module-specific data (leave types, payroll components, etc.)
        // is seeded by Database\Seeders\ModuleDataSeeder.
    }
}
