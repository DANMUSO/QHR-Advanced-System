<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Support\ApprovalRerouter;
use App\Modules\Employees\Support\SideEffects;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Events\EmployeeExited;
use App\Modules\Employees\Events\EmployeeReinstated;
use App\Modules\Employees\Events\EmployeeTransferred;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Models\EmployeeTransfer;
use App\Modules\Employees\Models\ExitRecord;
use App\Modules\Employees\Models\ResignationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exits, re-instatement, company transfers and internal moves (transfer / promotion / demotion / redeployment).
 * Future-dated exits and moves are stored as "scheduled" and applied by `employees:process-lifecycle`.
 */
class LifecycleService
{
    public function __construct(private EmployeeService $employees, private AccountService $accounts, private ContractService $contracts) {}

    // ── Exit ──────────────────────────────────────────────────────────────────

    public function recordExit(Employee $employee, array $data, ?User $by): ExitRecord
    {
        if (! in_array($employee->status, ['active', 'suspended'], true)) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name} is not an active employee."]);
        }
        if (ExitRecord::where('employee_id', $employee->id)->where('status', 'scheduled')->exists()) {
            throw ValidationException::withMessages(['employee_id' => "An exit is already scheduled for {$employee->full_name}."]);
        }

        return DB::transaction(function () use ($employee, $data, $by) {
            $exit = ExitRecord::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'exit_date' => $data['exit_date'],
                'notice_date' => $data['notice_date'] ?? null,
                'reason' => $data['reason'],
                'comments' => $data['comments'] ?? null,
                'status' => 'scheduled',
                'clearance_status' => 'pending',
                'clearance' => array_fill_keys(array_keys(ExitRecord::CLEARANCE_ITEMS), false),
                'successor_supervisor_id' => $data['new_supervisor_id'] ?? null,
                'reassignments' => $data['reassignments'] ?? null,
                'processed_by' => $by?->id,
            ]);
            activity('employees')->performedOn($employee)->causedBy($by)
                ->withProperties(['exit_date' => $exit->exit_date->toDateString(), 'reason' => $exit->reason])
                ->log('Recorded exit');

            // The resignation is handled as soon as HR records the exit, even one scheduled for a later date.
            app(SelfServiceRequestService::class)->markExited($employee, $exit->id);

            if (! $exit->exit_date->isFuture()) {
                $this->finalizeExit($exit);
            }

            return $exit->fresh();
        });
    }

    /** Applies an exit: status exited, reports reassigned, login disabled. */
    public function finalizeExit(ExitRecord $exit): void
    {
        DB::transaction(function () use ($exit) {
            $employee = $exit->employee()->first();
            $employee->forceFill(['status' => 'exited'])->save();

            $map = collect($exit->reassignments ?? [])->mapWithKeys(fn ($r) => [(int) $r['employee_id'] => $r['supervisor_id'] ?? null])->all();
            $result = $this->employees->reassignReports($employee, $map, $exit->successor_supervisor_id);

            // Someone who left can no longer head a branch or department.
            Branch::withoutGlobalScopes()->where('head_employee_id', $employee->id)->update(['head_employee_id' => null]);
            Department::withoutGlobalScopes()->where('head_employee_id', $employee->id)->update(['head_employee_id' => null]);

            EmployeeContract::withoutGlobalScopes()->where('employee_id', $employee->id)->whereIn('status', ['active', 'draft'])
                ->update(['status' => 'terminated', 'end_date' => DB::raw('COALESCE(end_date, '.DB::getPdo()->quote($exit->exit_date->toDateString()).')')]);

            if ($user = $employee->user()->first()) {
                $this->accounts->deactivate($user);
            }

            ContractAlerts::clearFor($employee->id);

            $exit->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            activity('employees')->performedOn($employee)->withProperties(['reports_reassigned' => $result])->log('Exit completed');

            DB::afterCommit(function () use ($employee, $exit) {
                SideEffects::dispatch(new EmployeeExited($employee, $exit));
                // The leaver can no longer approve: move their pending queue on now rather than at the nightly approvals:reroute.
                SideEffects::run('reroute approvals of leaver', function () use ($employee) {
                    $counts = app(ApprovalRerouter::class)->run(false, $employee->id);
                    if (array_sum($counts)) {
                        activity('employees')->performedOn($employee)->withProperties(['rerouted' => array_filter($counts)])->log('Pending approvals re-routed on exit');
                    }
                });
            });
        });
    }

    public function cancelExit(ExitRecord $exit): void
    {
        if ($exit->status !== 'scheduled') {
            throw ValidationException::withMessages(['exit' => 'Only scheduled exits can be cancelled.']);
        }
        $exit->forceFill(['status' => 'cancelled'])->save();
        // A resignation closed by this exit goes back to HR's queue.
        ResignationRequest::withoutGlobalScope('company')->where('exit_id', $exit->id)->where('status', 'processed')
            ->update(['status' => 'pending', 'exit_id' => null, 'decided_at' => null, 'decided_by' => null]);
        activity('employees')->performedOn($exit->employee()->first())->log('Cancelled scheduled exit');
    }

    // ── Re-instatement ────────────────────────────────────────────────────────

    public function reinstate(Employee $old, array $employment, bool $sendActivation, ?User $by): Employee
    {
        if ($old->status !== 'exited') {
            throw ValidationException::withMessages(['employee' => 'Only exited employees can be re-instated.']);
        }
        if (Employee::withoutGlobalScopes()->where('previous_employee_id', $old->id)->exists()) {
            throw ValidationException::withMessages(['employee' => 'This record has already been re-instated or transferred.']);
        }
        // The new period must start after the old one ended, or payroll pays (and charges statutory deductions on)
        // the overlapping days twice — once on each employment record.
        $exitDate = ExitRecord::withoutGlobalScopes()->where('employee_id', $old->id)->where('status', '!=', 'cancelled')->latest('exit_date')->value('exit_date');
        if ($exitDate && ! empty($employment['date_of_joining']) && \Carbon\Carbon::parse($employment['date_of_joining'])->lte(\Carbon\Carbon::parse($exitDate))) {
            throw ValidationException::withMessages(['date_of_joining' => 'The return date must be after the previous exit date ('.\Carbon\Carbon::parse($exitDate)->format('j M Y').').']);
        }

        return DB::transaction(function () use ($old, $employment, $sendActivation, $by) {
            $new = $this->employees->successor($old, $employment, $old->company_id);

            if ($user = $old->user()->first()) {
                $this->accounts->moveLogin($user, $old, $new);
                if ($sendActivation) {
                    $this->accounts->sendActivation($user);
                }
            } elseif ($sendActivation && $new->email && ! User::where('email', $new->email)->exists()) {
                $this->accounts->createLogin($new, true);
            }
            $this->accounts->syncSupervisorRoleById($new->primary_supervisor_id);
            $this->contracts->fromEmployment($new, $by);

            activity('employees')->performedOn($new)->causedBy($by)->withProperties(['previous_employee_id' => $old->id])->log('Re-instated (new employment period)');
            activity('employees')->performedOn($old)->causedBy($by)->withProperties(['new_employee_id' => $new->id])->log('Superseded by re-instatement');

            DB::afterCommit(fn () => SideEffects::dispatch(new EmployeeReinstated($new, $old)));

            return $new;
        });
    }

    // ── Company transfer ─────────────────────────────────────────────────────

    public function transferCompany(Employee $old, int $targetCompanyId, array $data, ?User $by): Employee
    {
        if (! in_array($old->status, ['active', 'suspended'], true)) {
            throw ValidationException::withMessages(['employee' => 'Only active employees can be transferred.']);
        }
        if ($old->company_id === $targetCompanyId) {
            throw ValidationException::withMessages(['company_id' => 'Choose a different company.']);
        }

        return DB::transaction(function () use ($old, $targetCompanyId, $data, $by) {
            $snapshot = $old->only(['branch_id', 'department_id', 'position_id', 'employment_level_id', 'primary_supervisor_id']);
            $new = $this->employees->successor($old, $data, $targetCompanyId);

            $old->forceFill(['status' => 'transferred'])->save();
            $map = collect($data['reassignments'] ?? [])->mapWithKeys(fn ($r) => [(int) $r['employee_id'] => $r['supervisor_id'] ?? null])->all();
            $this->employees->reassignReports($old, $map, $data['new_supervisor_id'] ?? null);
            Branch::withoutGlobalScopes()->where('head_employee_id', $old->id)->update(['head_employee_id' => null]);
            Department::withoutGlobalScopes()->where('head_employee_id', $old->id)->update(['head_employee_id' => null]);
            EmployeeContract::withoutGlobalScopes()->where('employee_id', $old->id)->where('status', 'active')
                ->update(['status' => 'superseded']);

            $removedRoles = [];
            if ($user = $old->user()->first()) {
                $removedRoles = $this->accounts->moveLogin($user, $old, $new);
            }
            $this->accounts->syncSupervisorRoleById($new->primary_supervisor_id);
            $this->contracts->fromEmployment($new, $by);

            $transfer = new EmployeeTransfer([
                'employee_id' => $old->id, 'new_employee_id' => $new->id, 'type' => 'company_transfer',
                'from_company_id' => $old->company_id, 'to_company_id' => $targetCompanyId,
                'from_branch_id' => $snapshot['branch_id'], 'to_branch_id' => $new->branch_id,
                'from_department_id' => $snapshot['department_id'], 'to_department_id' => $new->department_id,
                'from_position_id' => $snapshot['position_id'], 'to_position_id' => $new->position_id,
                'from_level_id' => $snapshot['employment_level_id'], 'to_level_id' => $new->employment_level_id,
                'from_supervisor_id' => $snapshot['primary_supervisor_id'], 'to_supervisor_id' => $new->primary_supervisor_id,
                'effective_date' => $new->date_of_joining ?? now(), 'reason' => $data['reason'] ?? null,
                'status' => 'completed', 'applied_at' => now(), 'processed_by' => $by?->id,
            ]);
            $transfer->forceFill(['company_id' => $old->company_id])->save();

            activity('employees')->performedOn($old)->causedBy($by)
                ->withProperties(['new_employee_id' => $new->id, 'to_company_id' => $targetCompanyId, 'roles_removed' => $removedRoles])
                ->log('Transferred to another company');
            activity('employees')->performedOn($new)->causedBy($by)->withProperties(['previous_employee_id' => $old->id])->log('Joined by company transfer');

            // Leave listens for carry_leave_balances (HR's choice on the transfer form).
            $options = ['carry_leave_balances' => (bool) ($data['carry_leave_balances'] ?? false)];
            DB::afterCommit(fn () => SideEffects::dispatch(new EmployeeTransferred($new, $old, $options)));

            return $new;
        });
    }

    // ── Internal moves ───────────────────────────────────────────────────────

    public function recordMove(Employee $employee, array $data, ?User $by): EmployeeTransfer
    {
        if (! in_array($employee->status, ['active', 'suspended'], true)) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name} is not an active employee."]);
        }

        return DB::transaction(function () use ($employee, $data, $by) {
            $move = new EmployeeTransfer([
                'employee_id' => $employee->id,
                'type' => $data['type'],
                'from_branch_id' => $employee->branch_id, 'to_branch_id' => $data['to_branch_id'] ?? $employee->branch_id,
                'from_department_id' => $employee->department_id, 'to_department_id' => $data['to_department_id'] ?? $employee->department_id,
                'from_position_id' => $employee->position_id, 'to_position_id' => $data['to_position_id'] ?? $employee->position_id,
                'from_level_id' => $employee->employment_level_id, 'to_level_id' => $data['to_level_id'] ?? $employee->employment_level_id,
                'from_supervisor_id' => $employee->primary_supervisor_id,
                'to_supervisor_id' => array_key_exists('to_supervisor_id', $data) && $data['to_supervisor_id'] ? $data['to_supervisor_id'] : $employee->primary_supervisor_id,
                'effective_date' => $data['effective_date'],
                'reason' => $data['reason'] ?? null,
                'status' => 'scheduled',
                'processed_by' => $by?->id,
            ]);
            $move->forceFill(['company_id' => $employee->company_id])->save();
            activity('employees')->performedOn($employee)->causedBy($by)
                ->withProperties(['type' => $move->type, 'effective_date' => $move->effective_date->toDateString()])
                ->log('Recorded '.(EmployeeTransfer::TYPES[$move->type] ?? $move->type));

            if (! $move->effective_date->isFuture()) {
                $this->applyMove($move);
            }

            return $move->fresh();
        });
    }

    public function applyMove(EmployeeTransfer $move): void
    {
        DB::transaction(function () use ($move) {
            $employee = $move->employee()->first();
            if (! $employee || ! in_array($employee->status, ['active', 'suspended'], true)) {
                $move->forceFill(['status' => 'cancelled'])->save();

                return;
            }
            $oldSupervisor = $employee->primary_supervisor_id;
            $employee->forceFill([
                'branch_id' => $move->to_branch_id, 'department_id' => $move->to_department_id,
                'position_id' => $move->to_position_id, 'employment_level_id' => $move->to_level_id,
                'primary_supervisor_id' => $move->to_supervisor_id === $employee->id ? null : $move->to_supervisor_id,
            ])->save();

            $this->accounts->syncSupervisorRole($employee);
            if ($oldSupervisor !== $employee->primary_supervisor_id) {
                $this->accounts->syncSupervisorRoleById($employee->primary_supervisor_id);
                $this->accounts->syncSupervisorRoleById($oldSupervisor);
            }
            $move->forceFill(['status' => 'completed', 'applied_at' => now()])->save();
        });
    }

    public function cancelMove(EmployeeTransfer $move): void
    {
        if ($move->status !== 'scheduled') {
            throw ValidationException::withMessages(['transfer' => 'Only scheduled moves can be cancelled.']);
        }
        $move->forceFill(['status' => 'cancelled'])->save();
    }

    /** Daily job: due exits and moves, expired contracts. Returns counts. */
    public function processDue(): array
    {
        $today = now()->toDateString();
        $exits = ExitRecord::where('status', 'scheduled')->whereDate('exit_date', '<=', $today)->get();
        $exits->each(fn ($x) => $this->finalizeExit($x));

        $moves = EmployeeTransfer::withoutGlobalScopes()->where('status', 'scheduled')->whereDate('effective_date', '<=', $today)->get();
        $moves->each(fn ($m) => $this->applyMove($m));

        // Expired contracts are flagged for HR (renew or exit) and HR + the supervisor are notified.
        $alerts = app(ContractAlerts::class);
        $expired = $alerts->flagExpired();
        $alerted = $alerts->sendUpcoming();

        return ['exits' => $exits->count(), 'moves' => $moves->count(), 'contracts_expired' => $expired, 'contract_alerts' => $alerted];
    }
}
