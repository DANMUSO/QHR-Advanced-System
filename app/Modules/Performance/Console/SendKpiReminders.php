<?php

namespace App\Modules\Performance\Console;

use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Notifications\PerformanceNotification;
use Illuminate\Console\Command;

class SendKpiReminders extends Command
{
    protected $signature = 'performance:kpi-reminders';

    protected $description = 'Send 7/3/1-day KPI due-date reminders to employees (§12)';

    public function handle(): int
    {
        $sent = 0;

        foreach ([7, 3, 1] as $days) {
            $target = today()->addDays($days)->toDateString();

            Goal::withoutGlobalScopes()
                ->whereIn('status', Goal::OPEN)
                ->whereDate('due_date', $target)
                ->with('employee.user', 'kpi')
                ->each(function (Goal $goal) use ($days, &$sent) {
                    $user = $goal->employee?->user;
                    if (! $user) {
                        return;
                    }

                    $kpiName = $goal->kpi?->name ?? 'KPI';
                    $user->notify(new PerformanceNotification(
                        title: "KPI due in {$days} day(s): {$kpiName}",
                        message: "Your KPI \"{$kpiName}\" is due on {$goal->due_date->toFormattedDateString()}. Please submit your score before the deadline.",
                        url: '/performance/my-goals',
                        icon: 'target',
                        mail: false, // SMS per spec; mail as fallback
                    ));

                    $sent++;
                });
        }

        $this->line("Sent {$sent} KPI reminder(s).");

        return self::SUCCESS;
    }
}
