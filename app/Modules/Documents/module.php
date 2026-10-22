<?php

use App\Models\Employee;
use App\Modules\Documents\Console\SendExpiryAlerts;
use App\Modules\Documents\Models\EmployeeDocument;
use Illuminate\Console\Scheduling\Schedule;

return [
    'name' => 'Documents',
    'order' => 61,

    // Daily 30- and 7-day expiry alerts to the employee and HR.
    'schedule' => fn (Schedule $s) => $s->command('documents:expiry-alerts')->dailyAt('07:15'),

    'boot' => function () {
        // Used by the HR file overview (withCount('documents')).
        Employee::resolveRelationUsing('documents', fn (Employee $e) => $e->hasMany(EmployeeDocument::class));

        \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(SendExpiryAlerts::class));
    },
];
