<?php

namespace App\Modules\Organization\Console;

use App\Modules\Organization\Models\Reminder;
use App\Modules\Organization\Services\ReminderRunner;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'organization:send-reminders {--company= : Only this company id} {--dry-run : List matches without notifying}';

    protected $description = 'Send the daily HR reminders configured under Settings → Reminders';

    public function handle(ReminderRunner $runner): int
    {
        $reminders = Reminder::query()->where('is_active', true)
            ->when($this->option('company'), fn ($q, $c) => $q->where('company_id', $c))->get();
        $today = now();

        foreach ($reminders as $reminder) {
            if ($this->option('dry-run')) {
                $n = $runner->matches($reminder, $today)->count();
            } else {
                $n = $runner->run($reminder, $today);
            }
            $this->line(sprintf('[%d] %-35s %d match(es)', $reminder->company_id, $reminder->name, $n));
        }
        $this->info("Processed {$reminders->count()} reminder rule(s).");

        return self::SUCCESS;
    }
}
