<?php

use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Schedule;

// Scheduled jobs are declared by each module manifest ('schedule' => fn (Schedule $s) => ...).
// Run `php artisan schedule:work` locally, or add `php artisan schedule:run` to cron / Task Scheduler every minute.
foreach (ModuleRegistry::callbacks('schedule') as $register) {
    $register(Schedule::getFacadeRoot());
}
