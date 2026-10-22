<?php

namespace App\Modules\Organization\Support;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Support\ApproverResolver;
use App\Modules\Organization\Models\Workflow;
use Illuminate\Support\Collection;

/**
 * Reads a company's configured approval chain (Workflow designer) and resolves who may decide each step.
 *
 * A step is ['type' => supervisor|secondary_supervisor|hod|role, 'role' => '<role name>' (type role only), 'label' => '...'].
 * Modules call ApprovalChain::steps($companyId, 'leave_request'); null means "no chain configured, use the built-in flow".
 */
class ApprovalChain
{
    /** @return array<int, array{type: string, role?: string, label?: string}>|null */
    public static function steps(int $companyId, string $trigger): ?array
    {
        $steps = Workflow::stepsFor($companyId, $trigger);
        if (! $steps) {
            return null;
        }
        $valid = array_values(array_filter($steps, fn ($s) => is_array($s) && isset(Workflow::STEP_TYPES[$s['type'] ?? ''])
            && (($s['type'] ?? '') !== 'role' || ! empty($s['role']))));

        return $valid ?: null;
    }

    /** Human label for a step, e.g. "Head of department" or "HR Administrator". */
    public static function label(array $step): string
    {
        return $step['label'] ?? (($step['type'] ?? '') === 'role' ? (string) ($step['role'] ?? 'Role') : (Workflow::STEP_TYPES[$step['type'] ?? ''] ?? 'Approver'));
    }

    /**
     * Users who may decide this step for the given subject employee. The subject never approves their own item.
     * Person-based steps (supervisor, secondary, HOD) escalate up the reporting line when that person can't act.
     *
     * @return Collection<int, User>
     */
    public static function usersFor(Employee $subject, array $step): Collection
    {
        $resolver = app(ApproverResolver::class);
        $personId = match ($step['type'] ?? null) {
            'supervisor' => $subject->primary_supervisor_id,
            'secondary_supervisor' => $subject->secondary_supervisor_id,
            'hod' => $subject->department?->head_employee_id,
            default => null,
        };

        if (($step['type'] ?? null) === 'role') {
            return User::query()->where('company_id', $subject->company_id)->where('is_active', true)
                ->role($step['role'])
                ->when($subject->user_id, fn ($q) => $q->whereKeyNot($subject->user_id))
                ->get();
        }

        $approver = $personId ? $resolver->resolve($personId, [$subject->id]) : null;

        return $approver?->user ? collect([$approver->user]) : collect();
    }

    /** Whether the user may decide this step for the subject. */
    public static function canDecide(User $user, Employee $subject, array $step): bool
    {
        return static::usersFor($subject, $step)->contains(fn (User $u) => $u->is($user));
    }
}
