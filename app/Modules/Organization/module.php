<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\Holiday;
use App\Models\Position;
use App\Modules\Organization\Console\RestoreBackup;
use App\Modules\Organization\Console\SendReminders;
use App\Modules\Organization\Support\CompanyMailer;
use App\Modules\Organization\Database\DemoSeeder;
use App\Modules\Organization\Services\WebhookDispatcher;
use Illuminate\Console\Scheduling\Schedule;

/*
| Organization module: companies, organisation structure (branches, departments, positions, levels,
| benefits, contact persons, holidays), company settings, branding, approval workflows, reminders,
| integrations, API tokens / webhooks, bulk import & export, data backups, help desk,
| and user / role administration.
| Uses the existing permission keys in config/hr.php (companies.manage, organization.manage,
| settings.manage, users.manage, users.impersonate).
*/

return [
    'name' => 'Organization',
    'order' => 10,

    'demo_seeder' => DemoSeeder::class,

    'schedule' => fn (Schedule $s) => $s->command('organization:send-reminders')->dailyAt('07:00'),

    'boot' => function () {
        EmploymentLevel::resolveRelationUsing('positions', fn ($level) => $level->hasMany(Position::class, 'employment_level_id'));
        Holiday::resolveRelationUsing('company', fn ($holiday) => $holiday->belongsTo(Company::class));

        \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(SendReminders::class));
        \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(RestoreBackup::class));

        // Company SMTP integration: mail notifications to a company's users go through its own server.
        CompanyMailer::register();

        // Outgoing webhooks for employee lifecycle events.
        $payload = fn (Employee $e) => ['id' => $e->id, 'employee_number' => $e->employee_number, 'name' => $e->full_name, 'email' => $e->email, 'status' => $e->status];
        Employee::created(fn (Employee $e) => WebhookDispatcher::dispatch($e->company_id, 'employee.created', $payload($e)));
        Employee::updated(function (Employee $e) use ($payload) {
            $event = $e->wasChanged('status') && $e->status === 'exited' ? 'employee.exited' : 'employee.updated';
            WebhookDispatcher::dispatch($e->company_id, $event, $payload($e) + ['changed' => array_keys($e->getChanges())]);
        });

        // organization.changed: any create / update / delete of a branch, department, position or level
        // (from the Structure screens or a bulk import).
        $structure = [Branch::class => 'branch', Department::class => 'department', Position::class => 'position', EmploymentLevel::class => 'level'];
        foreach ($structure as $class => $type) {
            foreach (['created', 'updated', 'deleted'] as $action) {
                $class::$action(fn ($m) => WebhookDispatcher::dispatch($m->company_id, 'organization.changed', [
                    'type' => $type, 'action' => $action, 'id' => $m->id, 'name' => $m->title ?? $m->name,
                ] + ($action === 'updated' ? ['changed' => array_keys($m->getChanges())] : [])));
            }
        }
    },
];
