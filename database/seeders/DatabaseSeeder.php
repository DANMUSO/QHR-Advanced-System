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

        $withDemo = app()->environment('local', 'testing') || in_array('--demo', $_SERVER['argv'] ?? []);

        if ($withDemo) {
            $this->call(DemoSeeder::class);
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
