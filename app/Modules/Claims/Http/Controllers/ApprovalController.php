<?php

namespace App\Modules\Claims\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Services\ClaimLimits;
use App\Modules\Claims\Services\ClaimPresenter;
use App\Modules\Claims\Services\ClaimWorkflow;
use App\Modules\Employees\Support\ApproverResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HR (Medical Approver) queue: approve — optionally a lower amount — or reject submitted claims. */
class ApprovalController extends Controller
{
    public function __construct(
        private ClaimWorkflow $workflow,
        private ClaimPresenter $presenter,
        private ClaimLimits $limits,
    ) {}

    /** Claims awaiting this user's HR decision (routed to them, or unrouted when they hold claims.approve). */
    public static function queueFor(User $user): Builder
    {
        $employeeId = $user->employee?->id;
        $canApprove = $user->can('claims.approve');

        return MedicalClaim::query()
            ->where('status', MedicalClaim::SUBMITTED)
            ->whereHas('employee', fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $user->id)))
            ->where(function ($q) use ($employeeId, $canApprove) {
                $q->when($employeeId, fn ($w) => $w->orWhere('routed_to_employee_id', $employeeId))
                    // Unrouted, or routed to someone without a usable login (no account / inactive / not activated).
                    ->when($canApprove, fn ($w) => $w->orWhere(fn ($x) => ApproverResolver::whereNoReachableApprover($x, 'routed_to_employee_id')))
                    ->when(! $employeeId && ! $canApprove, fn ($w) => $w->whereRaw('1 = 0'));
            });
    }

    /**
     * Who may open the approvals page: claims.approve holders, plus anyone a claim has been routed to
     * (supervisor / escalated approver) or who has decided one before (their "decided" history).
     */
    public static function canAccess(User $user): bool
    {
        if ($user->can('claims.approve')) {
            return true;
        }
        $employeeId = $user->employee?->id;

        return MedicalClaim::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('hr_decided_by', $user->id)
                ->when($employeeId, fn ($w) => $w->orWhere('routed_to_employee_id', $employeeId)))
            ->exists();
    }

    /** Front-end flag for the route guard and navigation. */
    public function access(Request $request): JsonResponse
    {
        return response()->json(['allowed' => static::canAccess($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(static::canAccess($user), 403, 'You have no claims to approve.');
        $scope = $request->query('scope', 'mine');

        $query = match ($scope) {
            'all' => MedicalClaim::where('status', MedicalClaim::SUBMITTED),
            'decided' => MedicalClaim::where('hr_decided_by', $user->id),
            default => static::queueFor($user),
        };
        abort_if($scope === 'all' && ! $user->can('claims.approve'), 403);

        $claims = $query->with(ClaimPresenter::LIST_WITH)->withCount('receipts')
            ->when($scope === 'decided', fn ($q) => $q->orderByDesc('hr_decided_at'), fn ($q) => $q->orderBy('submitted_at'))
            ->paginate($request->integer('per_page', 25));

        $rows = $claims->getCollection()->map(function (MedicalClaim $c) use ($user) {
            $row = $this->presenter->row($c, $user);
            if ($c->status === MedicalClaim::SUBMITTED && $c->employee) {
                $b = $this->limits->balanceFor($c->employee, $c->claim_date, $c->id);
                $row['balance'] = ['annual_balance' => $b['annual_balance'], 'monthly_balance' => $b['monthly_balance']];
            }

            return $row;
        });

        return response()->json(array_merge($claims->toArray(), [
            'data' => $rows,
            'counts' => [
                'mine' => static::queueFor($user)->count(),
                'all' => $user->can('claims.approve') ? MedicalClaim::where('status', MedicalClaim::SUBMITTED)->count() : null,
            ],
        ]));
    }

    public function approve(Request $request, MedicalClaim $claim): JsonResponse
    {
        abort_unless($this->workflow->canHrDecide($request->user(), $claim), 403, 'You cannot approve this claim.');
        $data = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $claim = $this->workflow->approve($claim, $request->user(), isset($data['approved_amount']) ? (float) $data['approved_amount'] : null, $data['note'] ?? null);

        return response()->json(['message' => 'Claim approved and sent to Finance.', 'data' => $this->presenter->row($claim->load(ClaimPresenter::LIST_WITH), $request->user())]);
    }

    public function reject(Request $request, MedicalClaim $claim): JsonResponse
    {
        abort_unless($this->workflow->canHrDecide($request->user(), $claim), 403, 'You cannot reject this claim.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $claim = $this->workflow->reject($claim, $request->user(), $data['reason']);

        return response()->json(['message' => 'Claim rejected.', 'data' => $this->presenter->row($claim->load(ClaimPresenter::LIST_WITH), $request->user())]);
    }
}
