<?php

namespace Database\Seeders;

use App\Support\ModuleRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('hr:sync-permissions');
        $this->call(HolidaySeeder::class);

        if (app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
            // Module demo data, in manifest order (modules may depend on earlier ones).
            foreach (ModuleRegistry::all() as $module) {
                if ($seeder = $module['manifest']['demo_seeder'] ?? null) {
                    $this->call($seeder);
                }
            }
            $this->call(ModuleDataSeeder::class);
            $this->call(DemoAccountsSeeder::class);
        }
    }
}
