<?php

namespace App\Modules\Leave\Console;

use App\Modules\Leave\Services\LeaveBalanceService;
use Illuminate\Console\Command;

class InitLeaveBalances extends Command
{
    protected $signature = 'leave:init-balances {--year= : Balance year, defaults to the current year} {--company= : Limit to one company id}';

    protected $description = 'Seed default leave types and create missing balances (with opening entitlement and accruals due) for active employees';

    public function handle(LeaveBalanceService $balances): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $created = $balances->initialiseAll($year, $this->option('company') ? (int) $this->option('company') : null);
        $this->info("$created balance(s) created for $year.");

        return self::SUCCESS;
    }
}
