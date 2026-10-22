<?php

namespace App\Modules\Benefits\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Models\TravelRequest;
use App\Modules\Benefits\Notifications\ExpenseNotification;
use App\Modules\Employees\Support\ApproverResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Routing, authorisation and notifications for expense claims and travel requests.
 * Both go to the employee's supervisor (escalating past anyone without a usable login, ApproverResolver);
 * when nobody in the reporting line can act they fall back to claims.approve / payroll.manage holders.
 * Approved expense claims then wait for Finance (claims.pay) to pay them directly or through payroll.
 */
class ExpenseWorkflow
{
    public const FALLBACK_PERMISSIONS = ['claims.approve', 'payroll.manage'];

    public function __construct(private ApproverResolver $resolver) {}

    public function approverIdFor(Employee $employee): ?int
    {
        return $this->resolver->resolveId($employee->primary_supervisor_id, [$employee->id]);
    }

    public static function canApproveAll(User $user): bool
    {
        return $user->canAny(self::FALLBACK_PERMISSIONS);
    }

    public function canDecide(User $user, ExpenseClaim|TravelRequest $item): bool
    {
        if ($item->employee_id === $user->employee?->id) {
            return false;
        }
        if ($user->employee && (int) $item->approver_employee_id === (int) $user->employee->id) {
            return true;
        }

        return static::canApproveAll($user);
    }

    /**
     * Items awaiting this user's decision: routed to them, or (for claims.approve / payroll.manage holders)
     * unrouted or routed to someone who can no longer sign in.
     */
    public static function pendingQuery(string $model, User $user): Builder
    {
        $employeeId = $user->employee?->id;
        $all = static::canApproveAll($user);
        $pending = $model === TravelRequest::class ? 'pending' : 'submitted';

        return $model::query()->where('status', $pending)
            ->when($employeeId, fn ($q) => $q->where('employee_id', '!=', $employeeId))
            ->where(function ($q) use ($employeeId, $all) {
                $q->when($employeeId, fn ($w) => $w->orWhere('approver_employee_id', $employeeId))
                    ->when($all, fn ($w) => $w->orWhere(fn ($x) => ApproverResolver::whereNoReachableApprover($x, 'approver_employee_id')))
                    ->when(! $employeeId && ! $all, fn ($w) => $w->whereRaw('1 = 0'));
            });
    }

    public function notifySubmitted(ExpenseClaim|TravelRequest $item): void
    {
        $users = $this->resolver->recipients($item->approver_employee_id, (int) $item->company_id, self::FALLBACK_PERMISSIONS, [$item->employee?->user_id]);
        $this->send($users, $item, 'submitted');
    }

    public function notifyEmployee(ExpenseClaim|TravelRequest $item, string $event): void
    {
        $this->send(collect([$item->employee?->user]), $item, $event);
    }

    public function notifyFinance(ExpenseClaim $claim): void
    {
        $users = $this->resolver->fallbackUsers((int) $claim->company_id, 'claims.pay', [$claim->employee?->user_id]);
        $this->send($users, $claim, 'awaiting_payment');
    }

    private function send(Collection $users, ExpenseClaim|TravelRequest $item, string $event): void
    {
        $users = $users->filter()->unique('id');
        if ($users->isNotEmpty()) {
            Notification::send($users, new ExpenseNotification($item->loadMissing('employee'), $event));
        }
    }
}
