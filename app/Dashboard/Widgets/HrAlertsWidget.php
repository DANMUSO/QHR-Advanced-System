<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Employee;
use App\Models\User;

/** Things HR should act on soon. */
class HrAlertsWidget extends Widget
{
    public function key(): string
    {
        return 'hr_alerts';
    }

    public function title(): string
    {
        return 'Needs attention';
    }

    public function visible(User $user): bool
    {
        return $user->can('dashboard.hr');
    }

    public function data(User $user): array
    {
        $pick = fn ($q, string $dateCol) => $q->with('department:id,name')->orderBy($dateCol)->limit(5)
            ->get(['id', 'first_name', 'last_name', 'department_id', 'photo_path', $dateCol]);

        $probations = Employee::active()->whereIn('probation_status', ['on_probation', 'extended'])
            ->whereBetween('probation_end_date', [now()->subDays(7), now()->addDays(30)]);
        $contracts = Employee::active()->where('contract_type', '!=', 'permanent')
            ->whereBetween('contract_end_date', [now(), now()->addDays(60)]);
        $notActivated = Employee::active()->whereHas('user', fn ($u) => $u->whereNull('activated_at'));
        $noSupervisor = Employee::active()->whereNull('primary_supervisor_id');

        return [
            'probations_ending' => ['count' => (clone $probations)->count(), 'items' => $pick($probations, 'probation_end_date')],
            'contracts_expiring' => ['count' => (clone $contracts)->count(), 'items' => $pick($contracts, 'contract_end_date')],
            'not_activated' => (clone $notActivated)->count(),
            // Who still has to activate, so HR can resend the email (or copy the link in local dev) from the widget.
            'not_activated_items' => (clone $notActivated)->whereHas('user', fn ($u) => $u->where('is_active', true))
                ->with('user:id,email')->latest('id')->limit(5)->get(['id', 'first_name', 'last_name', 'photo_path', 'user_id'])
                ->map(fn ($e) => ['id' => $e->id, 'user_id' => $e->user_id, 'first_name' => $e->first_name, 'last_name' => $e->last_name,
                    'email' => $e->user?->email, 'photo_url' => $e->photo_url])->values(),
            'can_manage_users' => $user->can('users.manage'),
            'can_copy_activation_link' => app()->environment('local') && $user->can('users.manage'),
            'without_supervisor' => (clone $noSupervisor)->count(),
            'without_account' => Employee::active()->whereNull('user_id')->count(),
            // Contracts that ended while the employee is still active: renew or exit (Employees module).
            'contracts_expired' => class_exists(\App\Modules\Employees\Services\ContractAlerts::class) ? (function () {
                $rows = \App\Modules\Employees\Services\ContractAlerts::actionRequired(5);

                return ['count' => \App\Modules\Employees\Models\EmployeeContract::query()->whereNotNull('action_required_at')->where('status', 'expired')
                    ->whereHas('employee', fn ($q) => $q->whereIn('status', ['active', 'suspended']))->count(),
                    'items' => $rows->map(fn ($c) => ['id' => $c->employee_id, 'first_name' => $c->employee?->first_name, 'last_name' => $c->employee?->last_name,
                        'photo_url' => $c->employee?->photo_url, 'reference' => $c->reference, 'end_date' => $c->end_date?->toDateString()])->values()];
            })() : ['count' => 0, 'items' => []],
        ];
    }
}
