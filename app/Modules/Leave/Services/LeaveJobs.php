<?php

namespace App\Modules\Leave\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveJobRun;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Notifications\LeaveJobNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Scheduled leave jobs (HR-FEATURES §3.6, §12). Each run is recorded per company and period,
 * and every posting carries a ledger reference, so re-running a period never double-posts.
 */
class LeaveJobs
{
    public function __construct(private LeaveBalanceService $balances, private LeaveWorkflow $workflow) {}

    /**
     * Monthly accrual for the period (YYYY-MM). Returns [companyName => summary].
     * $force re-runs a period already recorded as run: postings are still idempotent per
     * balance ("accrual:YYYY-MM" reference), so only missing accruals are credited.
     */
    public function accrue(string $period, ?int $companyId = null, bool $notify = true, bool $force = false): array
    {
        $periodStart = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $results = [];

        foreach (Company::query()->when($companyId, fn ($q) => $q->whereKey($companyId))->get() as $company) {
            if (! $force && LeaveJobRun::hasRun('accrual', $period, $company->id)) {
                $results[$company->name] = 'already run';

                continue;
            }
            LeaveType::seedDefaults($company);
            $types = LeaveType::withoutGlobalScopes()->where('company_id', $company->id)->active()->where('accrues_monthly', true)->where('has_balance', true)->get();
            $employees = 0;
            $days = 0.0;

            Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('date_of_joining')->orWhereDate('date_of_joining', '<', $periodStart))
                ->with('company')->chunkById(200, function ($chunk) use ($types, $periodStart, $period, $company, &$employees, &$days) {
                    foreach ($chunk as $employee) {
                        $credited = false;
                        foreach ($types as $type) {
                            $exists = LeaveBalance::withoutGlobalScopes()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $periodStart->year])->first();
                            $balance = $exists ?? $this->balances->ensure($employee, $type, $periodStart->year, $periodStart->copy()->endOfMonth());
                            if (! $balance) {
                                continue;
                            }
                            $rate = $type->monthlyAccrual($company);
                            $tx = $this->balances->post($balance, 'accrual', $rate, "Monthly accrual $period", reference: "accrual:$period", date: $periodStart);
                            if ($tx || ! $exists) {
                                $credited = true;
                                $days += $rate;
                            }
                        }
                        $employees += $credited ? 1 : 0;
                    }
                });

            $summary = ['employees' => $employees, 'days' => round($days, 2)];
            $run = LeaveJobRun::firstOrNew(['job' => 'accrual', 'period' => $period, 'company_id' => $company->id]);
            if (! $run->exists || $employees > 0) { // a forced re-run that credited nothing keeps the original record
                $run->fill(['records' => $employees, 'summary' => $summary, 'ran_at' => now()])->save();
            }
            if ($notify) {
                $this->notifyAdmins($company, new LeaveJobNotification('accrual', $period, $company->name, $summary));
            }
            $results[$company->name] = $summary;
        }

        return $results;
    }

    /**
     * Year-end: cap carry-forward types at the company cap, forfeit the excess, and open next-year balances.
     */
    public function carryForward(int $year, ?int $companyId = null, bool $notify = true): array
    {
        $results = [];
        $next = $year + 1;

        foreach (Company::query()->when($companyId, fn ($q) => $q->whereKey($companyId))->get() as $company) {
            if (LeaveJobRun::hasRun('carry_forward', (string) $year, $company->id)) {
                $results[$company->name] = 'already run';

                continue;
            }
            $types = LeaveType::withoutGlobalScopes()->where('company_id', $company->id)->where('carry_forward', true)->get()->keyBy('id');
            $processed = 0;
            $carried = 0.0;
            $forfeited = 0.0;
            $top = [];
            $yearEnd = Carbon::create($year, 12, 31);

            LeaveBalance::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)
                ->whereIn('leave_type_id', $types->keys())->with('employee')->chunkById(200, function ($rows) use ($types, $company, $year, $next, $yearEnd, &$processed, &$carried, &$forfeited, &$top) {
                    foreach ($rows as $balance) {
                        $type = $types[$balance->leave_type_id];
                        $cap = $type->carryForwardCap($company) ?? 0.0;
                        $available = $balance->available;
                        $carry = max(0, min($available, $cap));
                        $excess = max(0, round($available - $carry, 2));
                        if ($excess > 0) {
                            $this->balances->post($balance, 'forfeiture', -$excess, "Year-end forfeiture $year (cap {$cap} days)", reference: "forfeiture:$year", date: $yearEnd);
                            $forfeited += $excess;
                            $top[] = ['name' => $balance->employee?->full_name, 'forfeited' => $excess];
                        }
                        if ($carry > 0 && $balance->employee && $balance->employee->status === 'active') {
                            $nextBalance = $this->balances->ensure($balance->employee, $type, $next, Carbon::create($next, 1, 1));
                            if ($nextBalance) {
                                $this->balances->post($nextBalance, 'carry_forward', $carry, "Carried forward from $year", reference: "carry_forward:$year", date: Carbon::create($next, 1, 1));
                                $carried += $carry;
                            }
                        }
                        $processed++;
                    }
                });

            // Open next-year balances for every active employee.
            $opened = $this->balances->initialiseAll($next, $company->id);

            usort($top, fn ($a, $b) => $b['forfeited'] <=> $a['forfeited']);
            $summary = ['employees' => $processed, 'carried' => round($carried, 2), 'forfeited' => round($forfeited, 2), 'opened' => $opened, 'top_forfeitures' => array_slice($top, 0, 20)];
            LeaveJobRun::create(['job' => 'carry_forward', 'period' => (string) $year, 'company_id' => $company->id, 'records' => $processed, 'summary' => $summary, 'ran_at' => now()]);
            if ($notify) {
                $this->notifyAdmins($company, new LeaveJobNotification('carry_forward', (string) $year, $company->name, $summary));
            }
            $results[$company->name] = $summary;
        }

        return $results;
    }

    /** Daily reminder to approvers about requests waiting more than $days days. */
    public function remind(int $days = 2): int
    {
        $period = today()->toDateString();
        if (LeaveJobRun::hasRun('reminders', $period, null)) {
            return 0;
        }
        $sent = 0;
        LeaveRequest::withoutGlobalScopes()->where('status', LeaveRequest::PENDING_SUPERVISOR)
            ->where(fn ($q) => $q->where('updated_at', '<', now()->subDays($days)))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', today()))
            ->with('employee', 'leaveType')->chunkById(100, function ($rows) use (&$sent) {
                foreach ($rows as $leave) {
                    $this->workflow->send($this->workflow->supervisorRecipients($leave), $leave, 'reminder');
                    $leave->forceFill(['reminded_at' => now()])->saveQuietly();
                    $sent++;
                }
            });
        LeaveJobRun::create(['job' => 'reminders', 'period' => $period, 'company_id' => null, 'records' => $sent, 'summary' => ['reminders' => $sent], 'ran_at' => now()]);

        return $sent;
    }

    private function notifyAdmins(Company $company, LeaveJobNotification $notification): void
    {
        try {
            $admins = $this->workflow->leaveAdmins($company->id);
            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notification);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
