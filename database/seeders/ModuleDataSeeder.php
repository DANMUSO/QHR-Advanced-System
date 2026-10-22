<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ModuleDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LeaveDataSeeder::class,
            PayrollDataSeeder::class,
            AttendanceDataSeeder::class,
            AttendanceSetupSeeder::class,
            PerformanceDataSeeder::class,
            LearningSeeder::class,
            SuccessionSeeder::class,
            ClaimsDataSeeder::class,
            DocumentsDataSeeder::class,
            ProbationDataSeeder::class,
            EngagementDataSeeder::class,
            EmployeeExtrasSeeder::class,
            PayrollExtrasSeeder::class,
            OrganizationExtrasSeeder::class,
            SystemDataSeeder::class,
        ]);
    }
}
