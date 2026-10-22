<?php

namespace App\Modules\Employees\Console;

use App\Modules\Employees\Services\LifecycleService;
use Illuminate\Console\Command;

class ProcessLifecycle extends Command
{
    protected $signature = 'employees:process-lifecycle';

    protected $description = 'Apply scheduled exits and moves that are due, expire ended contracts (flagged for HR) and send 30/14/7-day contract alerts';

    public function handle(LifecycleService $lifecycle): int
    {
        $r = $lifecycle->processDue();
        $this->info("Exits completed: {$r['exits']}, moves applied: {$r['moves']}, contracts expired: {$r['contracts_expired']}, contract expiry alerts: {$r['contract_alerts']}.");

        return self::SUCCESS;
    }
}
