<?php

namespace App\Modules\Claims\Services;

use App\Models\User;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Models\MedicalClaimEvent;

/** JSON shapes for claims (list rows and full detail). */
class ClaimPresenter
{
    public const LIST_WITH = [
        'employee:id,company_id,first_name,last_name,employee_number,phone,photo_path,branch_id,department_id,user_id',
        'employee.department:id,name', 'employee.branch:id,name', 'routedTo:id,first_name,last_name',
    ];

    public function __construct(private ClaimWorkflow $workflow) {}

    public function row(MedicalClaim $c, ?User $viewer = null): array
    {
        $e = $c->employee;

        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'company_id' => $c->company_id,
            'employee' => $e ? [
                'id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number, 'phone' => $e->phone,
                'photo_url' => $e->photo_url, 'department' => $e->department?->name, 'branch' => $e->branch?->name,
            ] : null,
            'claimant_type' => $c->claimant_type,
            'claimant_type_label' => MedicalClaim::CLAIMANT_TYPES[$c->claimant_type] ?? $c->claimant_type,
            'claimant_name' => $c->claimant_name,
            'claim_date' => $c->claim_date?->toDateString(),
            'provider_name' => $c->provider_name,
            'total_amount' => (float) $c->total_amount,
            'approved_amount' => $c->approved_amount !== null ? (float) $c->approved_amount : null,
            'payable_amount' => $c->effectiveAmount(),
            'status' => $c->status,
            'status_label' => $c->statusLabel(),
            'hr_status' => match ($c->status) {
                MedicalClaim::SUBMITTED => 'pending',
                MedicalClaim::HR_REJECTED => 'rejected',
                MedicalClaim::CANCELLED => 'cancelled',
                default => 'approved',
            },
            'finance_status' => match ($c->status) {
                MedicalClaim::PAID => 'paid',
                MedicalClaim::FINANCE_REJECTED => 'rejected',
                MedicalClaim::HR_APPROVED => 'pending',
                default => null,
            },
            'routed_to' => $c->routedTo?->full_name,
            'payment_method' => $c->payment_method,
            'payment_reference' => $c->payment_reference,
            'paid_on' => $c->paid_on?->toDateString(),
            'payment_batch' => $c->payment_batch,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'hr_decided_at' => $c->hr_decided_at?->toIso8601String(),
            'finance_decided_at' => $c->finance_decided_at?->toIso8601String(),
            'receipts_count' => $c->receipts_count ?? null,
            'can' => $viewer ? [
                'edit' => $this->workflow->canEdit($viewer, $c),
                'hr_decide' => $this->workflow->canHrDecide($viewer, $c),
                'pay' => $this->workflow->canPay($viewer, $c),
            ] : null,
        ];
    }

    public function detail(MedicalClaim $c, User $viewer, array $balance): array
    {
        $c->loadMissing(['items', 'receipts', 'events.user:id,name', 'hrDecider:id,name', 'financeDecider:id,name', 'dependant:id,name,relationship']);

        return $this->row($c, $viewer) + [
            'dependant_id' => $c->dependant_id,
            'notes' => $c->notes,
            'hr_comment' => $c->hr_comment,
            'finance_comment' => $c->finance_comment,
            'hr_decided_by' => $c->hrDecider?->name,
            'finance_decided_by' => $c->financeDecider?->name,
            'items' => $c->items->map(fn ($i) => [
                'id' => $i->id, 'category' => $i->category, 'category_label' => MedicalClaim::CATEGORIES[$i->category] ?? $i->category,
                'description' => $i->description, 'amount' => (float) $i->amount,
            ])->values(),
            'receipts' => $c->receipts->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->original_name, 'mime_type' => $r->mime_type, 'size' => $r->size,
                'url' => "/api/claims/{$c->id}/receipts/{$r->id}",
            ])->values(),
            'timeline' => $c->events->map(fn (MedicalClaimEvent $ev) => [
                'id' => $ev->id, 'action' => $ev->action, 'from' => $ev->from_status, 'to' => $ev->to_status,
                'note' => $ev->note, 'meta' => $ev->meta, 'by' => $ev->user?->name ?? 'System', 'at' => $ev->created_at?->toIso8601String(),
            ])->values(),
            'balance' => $balance,
        ];
    }
}
