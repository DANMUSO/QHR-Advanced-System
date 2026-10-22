<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Dashboard\Widget;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/** Aggregates everything waiting for this user's decision across modules. */
class ApprovalsInboxWidget extends Widget
{
    public function key(): string
    {
        return 'approvals_inbox';
    }

    public function title(): string
    {
        return 'Approvals inbox';
    }

    public function size(): string
    {
        return 'lg';
    }

    /**
     * Approver roles always see the inbox; anyone else (e.g. a colleague asked to accept a leave handover)
     * sees it while at least one item waits for them.
     */
    public function visible(User $user): bool
    {
        if ($user->employee?->isSupervisor() || $user->hasAnyRole(['super-admin', 'hr-admin', 'finance', 'payroll-officer'])) {
            return true;
        }

        return $this->providers()->contains(fn (ApprovalProvider $p) => $this->safely(fn () => $p->countFor($user), 0) > 0);
    }

    public function data(User $user): array
    {
        $providers = $this->providers();

        $items = $providers->flatMap(fn (ApprovalProvider $p) => $this->safely(fn () => $p->pendingFor($user, 10), []))
            ->sortBy('requested_at')->values();

        $byType = $providers->flatMap(fn (ApprovalProvider $p) => collect($this->safely(fn () => $p->pendingFor($user, 1000), []))->pluck('type'))
            ->countBy()->all();

        return [
            'total' => $providers->sum(fn (ApprovalProvider $p) => $this->safely(fn () => $p->countFor($user), 0)),
            'by_type' => $byType,
            'items' => $items->take(10)->all(),
        ];
    }

    /** @return Collection<int, ApprovalProvider> */
    private function providers(): Collection
    {
        return collect(config('hr.approval_providers', []))
            ->merge(\App\Support\ModuleRegistry::collect('approval_providers'))
            ->unique()
            ->map(fn ($c) => app($c))
            ->filter(fn ($p) => $p instanceof ApprovalProvider)
            ->values();
    }

    /** One failing module provider must not break the dashboard. */
    private function safely(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
