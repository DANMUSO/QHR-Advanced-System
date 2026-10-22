<?php

namespace App\Modules\Leave\Console;

use App\Modules\Leave\Services\LeaveJobs;
use Illuminate\Console\Command;

class RemindPendingLeave extends Command
{
    protected $signature = 'leave:remind {--days=2 : Remind about requests pending longer than this}';

    protected $description = 'Remind approvers about leave requests awaiting a decision (once per day)';

    public function handle(LeaveJobs $jobs): int
    {
        $this->info($jobs->remind((int) $this->option('days')).' reminder(s) sent.');

        return self::SUCCESS;
    }
}
