<?php

namespace App\Modules\Leave\Console;

use App\Modules\Leave\Services\LeaveJobs;
use Illuminate\Console\Command;

class CarryForwardLeave extends Command
{
    protected $signature = 'leave:carry-forward {--year= : Year being closed, defaults to last year} {--company= : Limit to one company id}';

    protected $description = 'Year-end: cap carried-forward leave, forfeit the excess and open next-year balances (idempotent per year)';

    public function handle(LeaveJobs $jobs): int
    {
        $year = (int) ($this->option('year') ?: now()->subYear()->year);
        foreach ($jobs->carryForward($year, $this->option('company') ? (int) $this->option('company') : null) as $company => $result) {
            $this->line(is_array($result) ? "$company: {$result['employees']} balances, {$result['carried']} carried, {$result['forfeited']} forfeited" : "$company: $result");
        }

        return self::SUCCESS;
    }
}
