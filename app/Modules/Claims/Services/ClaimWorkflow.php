<?php

namespace App\Modules\Claims\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Support\ApproverResolver;
use App\Modules\Claims\Models\ClaimApprover;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Notifications\ClaimNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Medical claim workflow:
 *   submitted ──HR approve──► hr_approved ──finance mark paid──► paid
 *       │                          └──finance reject──► finance_rejected (balance restored)
 *       ├──HR reject──► hr_rejected
 *       └──claimant cancel──► cancelled
 */
class ClaimWorkflow
{
    public function __construct(private ClaimLimits $limits) {}

    /* ---------------------------------------------------------------- routing & recipients */

    /**
     * Employee the claim is routed to, or null (= anyone in the company holding claims.approve).
     * An approver without a usable login (none / inactive / not activated) escalates up their reporting line.
     */
    public function routeFor(Employee $claimant): ?int
    {
        $approverId = ClaimApprover::forCompany($claimant->company_id)?->medical_approver_id;
        if (! $approverId) {
            return null;
        }
        // The approver cannot approve their own claim: start at the claimant's supervisor instead.
        $start = (int) $approverId === (int) $claimant->id ? $claimant->primary_supervisor_id : $approverId;

        return app(ApproverResolver::class)->resolveId($start, [$claimant->id]);
    }

    /** Re-resolve the approver of a submitted claim (approvals:reroute). Returns true when it changed. */
    public function reroute(MedicalClaim $claim): bool
    {
        if ($claim->status !== MedicalClaim::SUBMITTED || ! $claim->employee) {
            return false;
        }
        $current = $claim->routed_to_employee_id;
        if (! $current) {
            return false; // unrouted claims already sit with every claims.approve holder
        }
        if (ApproverResolver::isReachable(Employee::withoutGlobalScopes()->with('user')->find($current))
            && (int) $current !== (int) $claim->employee_id) {
            return false; // still routed to someone who can act
        }
        $new = $this->routeFor($claim->employee);
        if ((int) $new === (int) $current) {
            return false;
        }
        $claim->forceFill(['routed_to_employee_id' => $new])->saveQuietly();
        $this->log($claim, null, 'rerouted', MedicalClaim::SUBMITTED, MedicalClaim::SUBMITTED, 'Approver had no usable login; re-routed.', ['from' => $current, 'to' => $new]);
        $this->notify($this->hrRecipients($claim), $claim, 'submitted');

        return true;
    }

    /** Users to notify / who see the claim in their HR queue. */
    public function hrRecipients(MedicalClaim $claim): Collection
    {
        $claimantUserId = $claim->employee?->user_id;

        return app(ApproverResolver::class)->recipients($claim->routed_to_employee_id, (int) $claim->company_id, 'claims.approve', [$claimantUserId]);
    }

    /** The company's Finance Payment Person, falling back to anyone holding claims.pay. */
    public function financeRecipients(MedicalClaim $claim): Collection
    {
        $financeId = ClaimApprover::forCompany($claim->company_id)?->finance_approver_id;
        $user = $financeId ? Employee::withoutGlobalScope('company')->find($financeId)?->user : null;
        if ($user && $user->is_active && $user->id !== $claim->employee?->user_id) {
            return collect([$user]);
        }

        return $this->usersWithPermission('claims.pay', $claim->company_id)
            ->reject(fn (User $u) => $u->id === $claim->employee?->user_id)->values();
    }

    public function usersWithPermission(string $permission, int $companyId): Collection
    {
        return User::permission($permission)->where('company_id', $companyId)->where('is_active', true)->get()
            ->reject(fn (User $u) => $u->hasRole('super-admin'))->values();
    }

    /* ---------------------------------------------------------------- authorisation */

    public function canView(User $user, MedicalClaim $claim): bool
    {
        return $claim->employee?->user_id === $user->id
            || ($user->employee && $claim->routed_to_employee_id === $user->employee->id)
            || $user->canAny(['claims.approve', 'claims.pay', 'claims.reports']);
    }

    public function canHrDecide(User $user, MedicalClaim $claim): bool
    {
        if ($claim->status !== MedicalClaim::SUBMITTED || $claim->employee?->user_id === $user->id) {
            return false;
        }
        if ($user->employee && $claim->routed_to_employee_id === $user->employee->id) {
            return true;
        }

        return $user->can('claims.approve');
    }

    public function canPay(User $user, MedicalClaim $claim): bool
    {
        return $claim->status === MedicalClaim::HR_APPROVED
            && $claim->employee?->user_id !== $user->id
            && $user->can('claims.pay');
    }

    public function canEdit(User $user, MedicalClaim $claim): bool
    {
        return $claim->status === MedicalClaim::SUBMITTED && $claim->employee?->user_id === $user->id;
    }

    /* ---------------------------------------------------------------- transitions */

    public function submit(Employee $employee, array $data, User $actor): MedicalClaim
    {
        // Enforce annual and monthly limits at submission time (§4.1 [Fix]).
        $amount = (float) ($data['total_amount'] ?? 0);
        $claimDate = isset($data['claim_date']) ? Carbon::parse($data['claim_date']) : now();
        $errors = $this->limits->violations($employee, $amount, $claimDate, excludeClaimId: null, includePending: true);
        if ($errors) {
            throw ValidationException::withMessages(['total_amount' => $errors[0]]);
        }

        return DB::transaction(function () use ($employee, $data, $actor) {
            $claim = new MedicalClaim($data);
            $claim->company_id = $employee->company_id;
            $claim->employee_id = $employee->id;
            $claim->reference = 'TMP-'.Str::random(12);
            $claim->status = MedicalClaim::SUBMITTED;
            $claim->submitted_at = now();
            $claim->routed_to_employee_id = $this->routeFor($employee);
            $claim->save();
            $claim->reference = sprintf('CLM-%s-%05d', $claim->claim_date->format('y'), $claim->id);
            $claim->saveQuietly();

            $this->log($claim, $actor, 'submitted', null, MedicalClaim::SUBMITTED, null, ['amount' => (float) $claim->total_amount]);

            DB::afterCommit(fn () => $this->notify($this->hrRecipients($claim), $claim, 'submitted'));

            return $claim;
        });
    }

    public function approve(MedicalClaim $claim, User $actor, ?float $amount, ?string $note): MedicalClaim
    {
        return DB::transaction(function () use ($claim, $actor, $amount, $note) {
            $claim = MedicalClaim::withoutGlobalScope('company')->lockForUpdate()->findOrFail($claim->id);
            $this->ensureStatus($claim, MedicalClaim::SUBMITTED);

            $amount ??= (float) $claim->total_amount;
            if ($amount <= 0 || $amount > (float) $claim->total_amount + 0.001) {
                throw ValidationException::withMessages(['approved_amount' => 'The approved amount must be greater than zero and not more than the amount claimed.']);
            }
            if ($amount < (float) $claim->total_amount && ! filled($note)) {
                throw ValidationException::withMessages(['note' => 'Explain why a lower amount is being approved.']);
            }
            // Re-check against approved claims only (limits may have changed since submission).
            $errors = $this->limits->violations($claim->employee, $amount, $claim->claim_date, $claim->id, includePending: false);
            if ($errors) {
                throw ValidationException::withMessages(['approved_amount' => $errors[0].' Approve a lower amount or reject the claim.']);
            }

            $partial = $amount < (float) $claim->total_amount;
            $claim->forceFill([
                'status' => MedicalClaim::HR_APPROVED,
                'approved_amount' => $amount,
                'hr_decided_by' => $actor->id,
                'hr_decided_at' => now(),
                'hr_comment' => $note,
            ])->save();

            $this->log($claim, $actor, $partial ? 'approved_partial' : 'approved', MedicalClaim::SUBMITTED, MedicalClaim::HR_APPROVED, $note, ['approved_amount' => $amount]);

            DB::afterCommit(function () use ($claim) {
                $this->notify(collect([$claim->employee?->user])->filter(), $claim, 'approved');
                $this->notify($this->financeRecipients($claim), $claim, 'awaiting_payment');
            });

            return $claim;
        });
    }

    public function reject(MedicalClaim $claim, User $actor, string $reason): MedicalClaim
    {
        return DB::transaction(function () use ($claim, $actor, $reason) {
            $claim = MedicalClaim::withoutGlobalScope('company')->lockForUpdate()->findOrFail($claim->id);
            $this->ensureStatus($claim, MedicalClaim::SUBMITTED);
            $claim->forceFill([
                'status' => MedicalClaim::HR_REJECTED,
                'hr_decided_by' => $actor->id,
                'hr_decided_at' => now(),
                'hr_comment' => $reason,
            ])->save();
            $this->log($claim, $actor, 'rejected', MedicalClaim::SUBMITTED, MedicalClaim::HR_REJECTED, $reason);
            DB::afterCommit(fn () => $this->notify(collect([$claim->employee?->user])->filter(), $claim, 'hr_rejected'));

            return $claim;
        });
    }

    public function markPaid(MedicalClaim $claim, User $actor, array $payment, ?string $batch = null): MedicalClaim
    {
        return DB::transaction(function () use ($claim, $actor, $payment, $batch) {
            $claim = MedicalClaim::withoutGlobalScope('company')->lockForUpdate()->findOrFail($claim->id);
            $this->ensureStatus($claim, MedicalClaim::HR_APPROVED);
            $claim->forceFill([
                'status' => MedicalClaim::PAID,
                'finance_decided_by' => $actor->id,
                'finance_decided_at' => now(),
                'finance_comment' => $payment['note'] ?? null,
                'payment_method' => $payment['payment_method'] ?? 'mpesa',
                'payment_reference' => $payment['payment_reference'] ?? null,
                'paid_on' => $payment['paid_on'] ?? today(),
                'payment_batch' => $batch,
            ])->save();
            $this->log($claim, $actor, 'paid', MedicalClaim::HR_APPROVED, MedicalClaim::PAID, $payment['note'] ?? null, [
                'method' => $claim->payment_method, 'reference' => $claim->payment_reference,
                'paid_on' => $claim->paid_on->toDateString(), 'amount' => $claim->effectiveAmount(), 'batch' => $batch,
            ]);
            DB::afterCommit(fn () => $this->notify(collect([$claim->employee?->user])->filter(), $claim, 'paid'));

            return $claim;
        });
    }

    public function financeReject(MedicalClaim $claim, User $actor, string $reason): MedicalClaim
    {
        return DB::transaction(function () use ($claim, $actor, $reason) {
            $claim = MedicalClaim::withoutGlobalScope('company')->lockForUpdate()->findOrFail($claim->id);
            $this->ensureStatus($claim, MedicalClaim::HR_APPROVED);
            $claim->forceFill([
                'status' => MedicalClaim::FINANCE_REJECTED,
                'finance_decided_by' => $actor->id,
                'finance_decided_at' => now(),
                'finance_comment' => $reason,
            ])->save();
            $this->log($claim, $actor, 'finance_rejected', MedicalClaim::HR_APPROVED, MedicalClaim::FINANCE_REJECTED, $reason,
                ['balance_restored' => $claim->effectiveAmount()]);
            DB::afterCommit(fn () => $this->notify(collect([$claim->employee?->user])->filter(), $claim, 'finance_rejected'));

            return $claim;
        });
    }

    public function cancel(MedicalClaim $claim, User $actor): MedicalClaim
    {
        $this->ensureStatus($claim, MedicalClaim::SUBMITTED);
        $claim->forceFill(['status' => MedicalClaim::CANCELLED, 'cancelled_at' => now()])->save();
        $this->log($claim, $actor, 'cancelled', MedicalClaim::SUBMITTED, MedicalClaim::CANCELLED);

        return $claim;
    }

    /* ---------------------------------------------------------------- approvers */

    /**
     * Assign the company's Medical Approver / Finance Payment Person. Grants claims.approve / claims.pay
     * directly to the new holder's user account and revokes the direct grant from the previous holder.
     */
    public function assignApprovers(Company $company, ?int $medicalId, ?int $financeId, User $actor): ClaimApprover
    {
        return DB::transaction(function () use ($company, $medicalId, $financeId, $actor) {
            $row = ClaimApprover::withoutGlobalScope('company')->firstOrNew(['company_id' => $company->id]);
            $previous = ['medical' => $row->medical_approver_id, 'finance' => $row->finance_approver_id];

            $this->swapPermission('claims.approve', $previous['medical'], $medicalId);
            $this->swapPermission('claims.pay', $previous['finance'], $financeId);

            $row->company_id = $company->id;
            $row->medical_approver_id = $medicalId;
            $row->finance_approver_id = $financeId;
            $row->updated_by = $actor->id;
            $row->save();

            activity('claims')->performedOn($row)->causedBy($actor)
                ->withProperties(['company' => $company->name, 'previous' => $previous, 'medical_approver_id' => $medicalId, 'finance_approver_id' => $financeId])
                ->log('Claim approvers updated');

            return $row;
        });
    }

    private function swapPermission(string $permission, ?int $oldEmployeeId, ?int $newEmployeeId): void
    {
        if ($oldEmployeeId === $newEmployeeId) {
            if ($newEmployeeId) {
                Employee::withoutGlobalScope('company')->find($newEmployeeId)?->user?->givePermissionTo($permission);
            }

            return;
        }
        if ($oldEmployeeId && ($old = Employee::withoutGlobalScope('company')->find($oldEmployeeId)?->user)) {
            if ($old->hasDirectPermission($permission)) {
                $old->revokePermissionTo($permission);
            }
        }
        if ($newEmployeeId && ($new = Employee::withoutGlobalScope('company')->find($newEmployeeId)?->user)) {
            $new->givePermissionTo($permission);
        }
    }

    /* ---------------------------------------------------------------- helpers */

    public function log(MedicalClaim $claim, ?User $actor, string $action, ?string $from, ?string $to, ?string $note = null, array $meta = []): void
    {
        $claim->events()->create([
            'user_id' => $actor?->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'note' => $note, 'meta' => $meta ?: null, 'created_at' => now(),
        ]);
        activity('claims')->performedOn($claim)->causedBy($actor)
            ->withProperties(array_filter(['reference' => $claim->reference, 'from' => $from, 'to' => $to, 'note' => $note] + $meta, fn ($v) => $v !== null))
            ->log('Claim '.str_replace('_', ' ', $action));
    }

    private function ensureStatus(MedicalClaim $claim, string $status): void
    {
        if ($claim->status !== $status) {
            throw ValidationException::withMessages(['status' => "This claim is {$claim->statusLabel()} and can no longer be changed this way."]);
        }
    }

    private function notify(Collection $users, MedicalClaim $claim, string $event): void
    {
        $users = $users->filter()->unique('id');
        if ($users->isNotEmpty()) {
            Notification::send($users, new ClaimNotification($claim->fresh(['employee']), $event));
        }
    }
}
