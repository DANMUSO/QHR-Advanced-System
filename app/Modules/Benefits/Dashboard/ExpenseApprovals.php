<?php

namespace App\Modules\Benefits\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Models\TravelRequest;
use App\Modules\Benefits\Services\ExpenseWorkflow;

/** Expense claims and travel requests awaiting this user's decision (supervisor, or the HR fallback). One-click approve/reject. */
class ExpenseApprovals implements ApprovalProvider
{
    public function pendingFor(User $user, int $limit = 10): array
    {
        $expenses = ExpenseWorkflow::pendingQuery(ExpenseClaim::class, $user)->with('employee:id,first_name,last_name')
            ->orderBy('created_at')->limit($limit)->get()
            ->map(fn (ExpenseClaim $e) => [
                'id' => $e->id,
                'type' => 'expense',
                'title' => ($e->employee?->full_name ?? 'Employee').' — expense KES '.number_format((float) $e->amount),
                'subtitle' => $e->description,
                'requested_at' => $e->created_at,
                'url' => '/expenses/approvals',
                'approve_endpoint' => "/expenses/{$e->id}/approve",
                'reject_endpoint' => "/expenses/{$e->id}/reject",
            ]);

        $travel = ExpenseWorkflow::pendingQuery(TravelRequest::class, $user)->with('employee:id,first_name,last_name')
            ->orderBy('departure_date')->limit($limit)->get()
            ->map(fn (TravelRequest $t) => [
                'id' => $t->id,
                'type' => 'travel',
                'title' => ($t->employee?->full_name ?? 'Employee').' — travel to '.$t->destination,
                'subtitle' => $t->departure_date?->format('d M').' – '.$t->return_date?->format('d M Y').' · KES '.number_format((float) $t->estimated_cost),
                'requested_at' => $t->created_at,
                'url' => '/expenses/approvals?tab=travel',
                'approve_endpoint' => "/expenses/travel/{$t->id}/approve",
                'reject_endpoint' => "/expenses/travel/{$t->id}/reject",
            ]);

        return $expenses->concat($travel)->sortBy('requested_at')->take($limit)->values()->all();
    }

    public function countFor(User $user): int
    {
        return ExpenseWorkflow::pendingQuery(ExpenseClaim::class, $user)->count()
            + ExpenseWorkflow::pendingQuery(TravelRequest::class, $user)->count();
    }
}
