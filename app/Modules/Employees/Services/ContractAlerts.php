<?php

namespace App\Modules\Employees\Services;

use App\Models\Employee;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Notifications\EmployeeNotification;
use App\Modules\Employees\Support\ApproverResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Contract expiry: alerts HR and the supervisor 30 / 14 / 7 days before an active contract ends, and when it ends
 * flags it for HR action (renew or exit). The flag clears once a new active contract is issued or the employee exits.
 */
class ContractAlerts
{
    public const DAYS = [30, 14, 7];

    public function __construct(private ApproverResolver $resolver) {}

    /** @return int alerts sent */
    public function sendUpcoming(): int
    {
        $sent = 0;
        foreach (self::DAYS as $days) {
            EmployeeContract::withoutGlobalScopes()->where('status', 'active')->whereDate('end_date', today()->addDays($days)->toDateString())
                ->get()->each(function (EmployeeContract $c) use ($days, &$sent) {
                    $employee = Employee::withoutGlobalScopes()->find($c->employee_id);
                    if (! $employee || ! in_array($employee->status, ['active', 'suspended'], true)) {
                        return;
                    }
                    $sent += $this->notify($employee, "Contract ending in {$days} days: {$employee->full_name}",
                        "{$employee->full_name}'s contract {$c->reference} ends on {$c->end_date->format('d M Y')}. Renew it or plan the exit.");
                });
        }

        return $sent;
    }

    /** Marks contracts that ended before today as expired + action required, and notifies. @return int */
    public function flagExpired(): int
    {
        $contracts = EmployeeContract::withoutGlobalScopes()->where('status', 'active')->whereNotNull('end_date')
            ->whereDate('end_date', '<', today()->toDateString())->get();

        foreach ($contracts as $c) {
            $c->forceFill(['status' => 'expired', 'action_required_at' => now()])->save();
            $employee = Employee::withoutGlobalScopes()->find($c->employee_id);
            if ($employee && in_array($employee->status, ['active', 'suspended'], true)) {
                activity('employees')->performedOn($employee)->withProperties(['contract' => $c->reference])->log('Contract expired: HR action required');
                $this->notify($employee, "Contract expired: {$employee->full_name}",
                    "{$employee->full_name}'s contract {$c->reference} ended on {$c->end_date->format('d M Y')} and the employee is still active. Renew the contract or exit the employee.");
            } else {
                $c->forceFill(['action_required_at' => null])->save();
            }
        }

        return $contracts->count();
    }

    /** Clears the HR-action flag once the employee has a new active contract or has left. */
    public static function clearFor(int $employeeId): void
    {
        EmployeeContract::withoutGlobalScopes()->where('employee_id', $employeeId)->whereNotNull('action_required_at')
            ->update(['action_required_at' => null]);
    }

    /** Expired contracts of current employees still awaiting HR action (HR alerts widget). */
    public static function actionRequired(int $limit = 5): Collection
    {
        return EmployeeContract::query()->whereNotNull('action_required_at')->where('status', 'expired')
            ->whereHas('employee', fn ($q) => $q->whereIn('status', ['active', 'suspended']))
            ->with(['employee:id,first_name,last_name,photo_path,department_id', 'employee.department:id,name'])
            ->orderBy('end_date')->limit($limit)->get();
    }

    private function notify(Employee $employee, string $title, string $message): int
    {
        $recipients = $this->resolver->fallbackUsers($employee->company_id, 'employees.update', [$employee->user_id]);
        $supervisor = $this->resolver->resolve($employee->primary_supervisor_id, [$employee->id]);
        if ($supervisor?->user) {
            $recipients = $recipients->push($supervisor->user)->unique('id');
        }
        if ($recipients->isEmpty()) {
            return 0;
        }
        try {
            Notification::send($recipients, new EmployeeNotification($title, $message, "/employees/{$employee->id}?tab=contracts"));
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }

        return 1;
    }
}
