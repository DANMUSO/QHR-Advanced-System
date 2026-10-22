<?php

namespace App\Modules\Leave\Console;

use App\Modules\Leave\Services\LeaveJobs;
use Illuminate\Console\Command;

class AccrueLeave extends Command
{
    protected $signature = 'leave:accrue {--period= : Month to accrue (YYYY-MM), defaults to the current month} {--company= : Limit to one company id} {--force : Re-run a period already recorded as run (only missing accruals are posted)}';

    protected $description = 'Monthly leave accrual for active employees (idempotent per company and month)';

    public function handle(LeaveJobs $jobs): int
    {
        $period = $this->option('period') ?: now()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error('Period must be YYYY-MM.');

            return self::FAILURE;
        }
        foreach ($jobs->accrue($period, $this->option('company') ? (int) $this->option('company') : null, force: (bool) $this->option('force')) as $company => $result) {
            $this->line(is_array($result) ? "$company: {$result['employees']} employees, {$result['days']} days" : "$company: $result");
        }

        return self::SUCCESS;
    }
}
