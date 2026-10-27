<?php

namespace App\Modules\Employees\Support;

use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Models\AttendanceTimesheet;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Models\TravelRequest;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Services\ClaimWorkflow;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveWorkflow;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Services\GoalWorkflow;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Services\ProbationRouting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Re-routes pending approvals whose approver can no longer act (ApproverResolver::isReachable) to the next reachable
 * supervisor up the reporting line, else to the module's admins. Used nightly by `approvals:reroute` and immediately
 * when an employee exits (only the leaver's queue). Idempotent.
 *
 * Covered: leave, medical claims, KPI submissions, probation reviews, expense claims and travel requests
 * (approver_employee_id). Overtime and timesheets have no stored approver (they are decided by whoever currently
 * supervises the employee), so they follow the reporting line automatically; they are re-routed here only if an
 * approver_employee_id column is ever added.
 */
class ApprovalRerouter
{
    /** Tables routed through a plain approver_employee_id column: model => open statuses. */
    public const SIMPLE = [
        ExpenseClaim::class => ['submitted'],
        TravelRequest::class => ['pending'],
        AttendanceOvertime::class => ['pending'],
        AttendanceTimesheet::class => ['submitted'],
    ];

    /** @var callable|null */
    private $warn;

    public function __construct(private ApproverResolver $resolver) {}

    /**
     * @param  int|null  $approverId  only items currently routed to this employee (exit); null = everything
     * @return array<string, int> module => rows re-routed
     */
    public function run(bool $dry = false, ?int $approverId = null, ?callable $warn = null): array
    {
        $this->warn = $warn;
        $counts = [];
        $only = fn (Builder $q, array $cols) => $approverId ? $q->where(fn ($w) => collect($cols)->each(fn ($c) => $w->orWhere($c, $approverId))) : $q;

        if (class_exists(LeaveRequest::class)) {
            $workflow = app(LeaveWorkflow::class);
            $cols = ['supervisor_id', 'handover1_approver_id', 'handover2_approver_id'];
            $counts['leave'] = $this->each($only(LeaveRequest::withoutGlobalScopes()->whereIn('status', LeaveRequest::OPEN), $cols), function (LeaveRequest $leave) use ($workflow, $dry, $cols) {
                if ($dry) {
                    $before = $leave->only($cols);
                    $workflow->assignApprovers($leave);

                    return $before != $leave->only($cols);
                }

                return $workflow->reroute($leave);
            });
        }

        if (class_exists(MedicalClaim::class)) {
            $workflow = app(ClaimWorkflow::class);
            $counts['claims'] = $this->each($only(MedicalClaim::withoutGlobalScopes()->where('status', MedicalClaim::SUBMITTED), ['routed_to_employee_id'])->with('employee'),
                fn (MedicalClaim $claim) => $dry
                    ? $claim->routed_to_employee_id && (int) $workflow->routeFor($claim->employee) !== (int) $claim->routed_to_employee_id
                        && ! ApproverResolver::isReachable(Employee::withoutGlobalScopes()->find($claim->routed_to_employee_id))
                    : $workflow->reroute($claim));
        }

        if (class_exists(Goal::class)) {
            $workflow = app(GoalWorkflow::class);
            $counts['kpi_submissions'] = $this->each($only(Goal::withoutGlobalScopes()->whereIn('status', ['submitted', 'under_review'])->whereNotNull('approver_id'), ['approver_id']),
                fn (Goal $goal) => $dry ? false : $workflow->reroute($goal));
        }

        if (class_exists(ProbationReview::class)) {
            $routing = app(ProbationRouting::class);
            // Nightly runs also reopen stages skipped earlier (nobody could act then) once someone can, even at the HR step.
            $statuses = $approverId ? ProbationRouting::OPEN : ProbationRouting::UNDECIDED;
            $counts['probation'] = $this->each($only(ProbationReview::withoutGlobalScopes()->whereIn('status', $statuses), ['primary_approver_id']),
                fn (ProbationReview $review) => $dry ? false : ($routing->reopenSkipped($review) || $routing->assign($review)));
        }

        foreach (self::SIMPLE as $model => $statuses) {
            if (! class_exists($model) || ! Schema::hasColumn((new $model)->getTable(), 'approver_employee_id')) {
                continue;
            }
            $key = str(class_basename($model))->snake()->plural()->toString();
            $query = $model::withoutGlobalScopes()->whereIn('status', $statuses);
            ApproverResolver::whereApproverUnreachable($query, 'approver_employee_id');
            $counts[$key] = $this->each($only($query, ['approver_employee_id']), fn ($row) => $this->rerouteSimple($row, $dry));
        }

        return $counts;
    }

    /** Next reachable supervisor of the requester; null leaves it to the module's admins (they see unrouted items). */
    private function rerouteSimple($row, bool $dry): bool
    {
        if (ApproverResolver::isReachable(Employee::withoutGlobalScopes()->find($row->approver_employee_id))) {
            return false;
        }
        $employee = Employee::withoutGlobalScopes()->find($row->employee_id);
        $new = $this->resolver->resolveId($employee?->primary_supervisor_id, [$row->employee_id]);
        if ((int) $new === (int) $row->approver_employee_id) {
            return false;
        }
        if (! $dry) {
            $from = $row->approver_employee_id;
            $row->forceFill(['approver_employee_id' => $new])->save();
            activity('approvals')->performedOn($row)->withProperties(['from' => $from, 'to' => $new])->log('Approval re-routed (approver unavailable)');
        }

        return true;
    }

    private function each($query, callable $callback): int
    {
        $changed = 0;
        $query->chunkById(100, function ($rows) use ($callback, &$changed) {
            foreach ($rows as $row) {
                try {
                    if ($callback($row)) {
                        $changed++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                    if ($this->warn) {
                        ($this->warn)(class_basename($row)." #{$row->id}: {$e->getMessage()}");
                    }
                }
            }
        });

        return $changed;
    }
}
