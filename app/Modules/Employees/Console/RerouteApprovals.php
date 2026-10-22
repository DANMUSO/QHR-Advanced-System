<?php

namespace App\Modules\Employees\Console;

use App\Modules\Employees\Support\ApprovalRerouter;
use Illuminate\Console\Command;

/**
 * Re-routes pending approvals whose approver has no usable login (no account, inactive, or never activated) to the
 * next reachable supervisor up the reporting line, else to the module's admins (ApprovalRerouter). Idempotent.
 */
class RerouteApprovals extends Command
{
    protected $signature = 'approvals:reroute {--dry-run : Report what would change without saving} {--approver= : Only items routed to this employee id}';

    protected $description = 'Re-route pending leave, claim, KPI, probation, expense and travel approvals stuck with approvers who cannot log in';

    public function handle(ApprovalRerouter $rerouter): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = $rerouter->run($dry, $this->option('approver') ? (int) $this->option('approver') : null, fn (string $m) => $this->warn($m));

        $this->table(['Module', $dry ? 'Would re-route' : 'Re-routed'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        return self::SUCCESS;
    }
}
