<?php

namespace App\Modules\Leave\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveTransaction;
use App\Modules\Leave\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave balances as a ledger: every change is a LeaveTransaction that also moves one column of the
 * employee × type × year LeaveBalance row (see LeaveBalance::COLUMN).
 */
class LeaveBalanceService
{
    /**
     * The balance row for an employee, type and year, creating it with opening entitlement
     * (and any monthly accruals already due) when it does not exist yet.
     * Returns null for types without a balance or that the employee is not eligible for.
     */
    public function ensure(Employee $employee, LeaveType $type, int $year, ?Carbon $asOf = null): ?LeaveBalance
    {
        $type = $type->ledgerType();
        if (! $type->has_balance || ! $type->eligible($employee)) {
            return null;
        }

        $balance = LeaveBalance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year)->first();
        if ($balance) {
            return $balance;
        }

        return DB::transaction(function () use ($employee, $type, $year, $asOf) {
            $balance = LeaveBalance::withoutGlobalScopes()->firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                ['company_id' => $employee->company_id],
            );
            if (! $balance->wasRecentlyCreated) {
                return $balance;
            }
            $this->applyOpening($balance, $employee, $type, $year, $asOf ?? now());

            return $balance->refresh();
        });
    }

    /** Opening entitlement for a newly created balance row. */
    private function applyOpening(LeaveBalance $balance, Employee $employee, LeaveType $type, int $year, Carbon $asOf): void
    {
        $joined = $employee->date_of_joining ? Carbon::parse($employee->date_of_joining) : null;
        if ($joined && $joined->year > $year) {
            return; // not employed in that year
        }
        $joinedThisYear = $joined && $joined->year === $year;

        if ($type->accrues_monthly) {
            $company = $employee->company ?? Company::find($employee->company_id);
            $rate = $type->monthlyAccrual($company);
            if ($joinedThisYear) {
                $this->post($balance, 'opening', $type->openingDays($company) ?? $rate, 'Opening balance (new hire)', reference: "opening:$year", date: $joined);
            }
            // Accruals already due this year (the monthly job runs on the 1st; it skips months posted here).
            $lastMonth = $year < $asOf->year ? 12 : ($year === $asOf->year ? $asOf->month : 0);
            $firstMonth = $joinedThisYear ? $joined->month + 1 : 1;
            for ($m = $firstMonth; $m <= $lastMonth; $m++) {
                $period = sprintf('%d-%02d', $year, $m);
                $this->post($balance, 'accrual', $rate, "Monthly accrual $period", reference: "accrual:$period", date: Carbon::create($year, $m, 1));
            }

            return;
        }

        $company = $employee->company ?? Company::find($employee->company_id);
        $opening = $type->openingDays($company);
        $days = $joinedThisYear && $opening !== null ? $opening : (float) $type->entitlement($company);
        if ($days > 0) {
            $this->post($balance, 'opening', $days, $joinedThisYear ? 'Opening balance (new hire)' : "Entitlement $year", reference: "opening:$year", date: Carbon::create($year, 1, 1)->max($joined ?? Carbon::create($year, 1, 1)));
        }
    }

    /**
     * Post a ledger transaction. $days is the signed effect on the available balance
     * (deductions and forfeitures negative). Returns null when the reference was already posted.
     */
    public function post(LeaveBalance $balance, string $type, float $days, ?string $reason = null, ?int $actorId = null, ?LeaveRequest $request = null, ?string $reference = null, ?Carbon $date = null): ?LeaveTransaction
    {
        return DB::transaction(function () use ($balance, $type, $days, $reason, $actorId, $request, $reference, $date) {
            $locked = LeaveBalance::withoutGlobalScopes()->lockForUpdate()->find($balance->id);
            if ($reference && LeaveTransaction::withoutGlobalScopes()->where('leave_balance_id', $locked->id)->where('reference', $reference)->exists()) {
                return null;
            }
            $column = LeaveBalance::COLUMN[$type];
            // taken / forfeited grow when the available balance shrinks.
            $delta = in_array($column, ['taken', 'forfeited'], true) ? -$days : $days;
            $locked->{$column} = round($locked->{$column} + $delta, 2);
            $locked->save();
            $balance->setRawAttributes($locked->getAttributes(), true);

            $tx = new LeaveTransaction([
                'leave_balance_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'leave_type_id' => $locked->leave_type_id,
                'leave_request_id' => $request?->id,
                'type' => $type,
                'days' => round($days, 2),
                'reason' => $reason,
                'reference' => $reference,
                'actor_id' => $actorId,
                'effective_date' => ($date ?? now())->toDateString(),
            ]);
            $tx->company_id = $locked->company_id;
            $tx->save();

            return $tx;
        });
    }

    /** Days held by open (not yet decided) requests against a ledger type and year. */
    public function pendingDays(Employee $employee, LeaveType $ledgerType, int $year, ?int $exceptRequestId = null): float
    {
        $typeIds = LeaveType::withoutGlobalScopes()->where('company_id', $ledgerType->company_id)
            ->where(fn ($q) => $q->where('id', $ledgerType->id)->orWhere('balance_type_id', $ledgerType->id))->pluck('id');

        return (float) LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)->whereIn('leave_type_id', $typeIds)
            ->whereIn('status', LeaveRequest::OPEN)->whereYear('start_date', $year)
            ->when($exceptRequestId, fn ($q) => $q->where('id', '!=', $exceptRequestId))
            ->sum('days');
    }

    /** Balance cards for an employee: every active type they are eligible for. */
    public function summary(Employee $employee, ?int $year = null): Collection
    {
        $year ??= now()->year;
        $types = LeaveType::withoutGlobalScopes()->where('company_id', $employee->company_id)->active()
            ->with('balanceType')->orderBy('sort_order')->get();

        return $types->filter(fn (LeaveType $t) => $t->eligible($employee))->map(function (LeaveType $t) use ($employee, $year) {
            $ledger = $t->ledgerType();
            $balance = $this->ensure($employee, $ledger, $year);
            $pending = $ledger->has_balance ? $this->pendingDays($employee, $ledger, $year) : 0.0;

            return [
                'leave_type_id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'color' => $t->color,
                'paid' => $t->paid,
                'counting' => $t->counting,
                'requires_document' => $t->requires_document,
                'allow_half_day' => $t->allow_half_day,
                'allow_hours' => $t->allow_hours,
                'has_balance' => $ledger->has_balance,
                'draws_from' => $t->balance_type_id ? $ledger->name : null,
                'entitlement' => $ledger->entitlement($employee->company ?? Company::find($employee->company_id)),
                'year' => $year,
                'entitled' => $balance?->entitled ?? 0,
                'accrued' => $balance?->accrued ?? 0,
                'carried_forward' => $balance?->carried_forward ?? 0,
                'adjusted' => $balance?->adjusted ?? 0,
                'taken' => $balance?->taken ?? 0,
                'forfeited' => $balance?->forfeited ?? 0,
                'earned' => $balance?->earned ?? 0,
                'available' => $balance?->available ?? null,
                'pending' => $pending,
                'projected' => $balance ? round($balance->available - $pending, 2) : null,
            ];
        })->values();
    }

    /** Initialise balances for every active employee (all companies, or one) for a year. Returns rows created. */
    public function initialiseAll(int $year, ?int $companyId = null): int
    {
        $created = 0;
        Company::query()->when($companyId, fn ($q) => $q->whereKey($companyId))->each(function (Company $company) use ($year, &$created) {
            LeaveType::seedDefaults($company);
            $types = LeaveType::withoutGlobalScopes()->where('company_id', $company->id)->active()->where('has_balance', true)->whereNull('balance_type_id')->get();
            Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
                ->with('company')->chunkById(200, function ($employees) use ($types, $year, &$created) {
                    foreach ($employees as $employee) {
                        foreach ($types as $type) {
                            $exists = LeaveBalance::withoutGlobalScopes()->where(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year])->exists();
                            if (! $exists && $this->ensure($employee, $type, $year)) {
                                $created++;
                            }
                        }
                    }
                });
        });

        return $created;
    }

    /**
     * Company transfer / reinstatement (HR-FEATURES §2.8): the employee now has a new record. With $carry, each
     * current-year balance of the old record moves to the new record's type with the same code (transfer_out on
     * the old ledger, transfer_in on the new one); types without a match, or everything when not carrying, get
     * fresh opening balances. Idempotent per old balance (ledger references).
     *
     * @return array{carried: array<string, float>, fresh: int}
     */
    public function transferBalances(Employee $new, Employee $old, bool $carry, ?int $year = null): array
    {
        $year ??= now()->year;
        $summary = ['carried' => [], 'fresh' => 0];
        $newTypes = LeaveType::withoutGlobalScopes()->where('company_id', $new->company_id)->active()
            ->where('has_balance', true)->whereNull('balance_type_id')->get()->keyBy(fn ($t) => strtoupper($t->code));
        $carried = [];

        if ($carry) {
            $oldBalances = LeaveBalance::withoutGlobalScopes()->where('employee_id', $old->id)->where('year', $year)->with('leaveType')->get();
            foreach ($oldBalances as $oldBalance) {
                $code = strtoupper((string) $oldBalance->leaveType?->code);
                $target = $newTypes[$code] ?? null;
                if (! $target || ! $target->eligible($new)) {
                    continue;
                }
                $days = $oldBalance->available;
                $newBalance = DB::transaction(function () use ($new, $target, $year) {
                    // Created without the opening entitlement: the carried balance replaces it.
                    return LeaveBalance::withoutGlobalScopes()->firstOrCreate(
                        ['employee_id' => $new->id, 'leave_type_id' => $target->id, 'year' => $year],
                        ['company_id' => $new->company_id],
                    );
                });
                $ref = "transfer:{$oldBalance->id}";
                if ($days != 0) {
                    $this->post($oldBalance, 'transfer_out', -$days, "Transferred to {$new->employee_number}", reference: $ref);
                    $this->post($newBalance, 'transfer_in', $days, "Transferred from {$old->employee_number}", reference: $ref);
                }
                $carried[$target->id] = true;
                $summary['carried'][$code] = $days;
            }
        }

        foreach ($newTypes as $type) {
            if (! isset($carried[$type->id]) && $this->ensure($new, $type, $year)?->wasRecentlyCreated) {
                $summary['fresh']++;
            }
        }

        return $summary;
    }

    /**
     * Reconciliation: recompute each balance row of a year from its ledger and report (or fix) rows whose stored
     * columns drifted from the transactions. The ledger is the source of truth.
     *
     * @return array<int, array{id: int, employee: ?string, leave_type: ?string, stored: float, ledger: float, columns: array}>
     */
    public function reconcile(int $year, ?int $companyId, bool $apply = false): array
    {
        $diffs = [];
        LeaveBalance::withoutGlobalScopes()->where('year', $year)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->with('employee:id,first_name,last_name,employee_number', 'leaveType:id,name')
            ->chunkById(200, function ($rows) use (&$diffs, $apply) {
                $sums = LeaveTransaction::withoutGlobalScopes()->whereIn('leave_balance_id', $rows->pluck('id'))
                    ->selectRaw('leave_balance_id, type, SUM(days) as days')->groupBy('leave_balance_id', 'type')->get()->groupBy('leave_balance_id');
                foreach ($rows as $balance) {
                    $expected = array_fill_keys(array_unique(array_values(LeaveBalance::COLUMN)), 0.0);
                    foreach ($sums[$balance->id] ?? [] as $row) {
                        $column = LeaveBalance::COLUMN[$row->type] ?? null;
                        if ($column) {
                            $expected[$column] += in_array($column, ['taken', 'forfeited'], true) ? -(float) $row->days : (float) $row->days;
                        }
                    }
                    $changed = [];
                    foreach ($expected as $column => $value) {
                        if (abs(round($value, 2) - (float) $balance->{$column}) > 0.001) {
                            $changed[$column] = ['stored' => (float) $balance->{$column}, 'ledger' => round($value, 2)];
                        }
                    }
                    if (! $changed) {
                        continue;
                    }
                    $stored = $balance->available;
                    if ($apply) {
                        LeaveBalance::withoutGlobalScopes()->whereKey($balance->id)->update(array_map(fn ($c) => $c['ledger'], $changed));
                    }
                    $balance->forceFill(array_map(fn ($c) => $c['ledger'], $changed));
                    $diffs[] = [
                        'id' => $balance->id,
                        'employee' => $balance->employee?->full_name,
                        'employee_number' => $balance->employee?->employee_number,
                        'leave_type' => $balance->leaveType?->name,
                        'stored' => $stored,
                        'ledger' => $balance->available,
                        'columns' => $changed,
                    ];
                }
            });

        return $diffs;
    }
}
