<?php

namespace App\Modules\Benefits\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Benefits\Models\TravelRequest;
use App\Modules\Benefits\Services\ExpenseWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Travel request approval queue: the employee's supervisor (or the HR fallback) approves, or rejects with a reason. */
class TravelApprovalController extends Controller
{
    public function __construct(private ExpenseWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $pendingIds = ExpenseWorkflow::pendingQuery(TravelRequest::class, $user)->pluck('id');

        $rows = TravelRequest::with('employee:id,first_name,last_name,photo_path,department_id', 'employee.department:id,name')
            ->where(fn ($q) => $q->whereIn('id', $pendingIds)
                ->orWhere(fn ($w) => $w->where('decided_by', $user->id)->where('decided_at', '>=', now()->subDays(30))))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderBy('departure_date')->get();

        return response()->json(['data' => $rows->map(fn (TravelRequest $t) => $this->present($t))]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'approved');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'rejected');
    }

    private function decide(Request $request, int $id, string $status): JsonResponse
    {
        $request->validate(['reason' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'min:3', 'max:500']]);
        $trip = TravelRequest::with('employee.user')->findOrFail($id);
        $user = $request->user();

        abort_if($trip->employee_id === $user->employee?->id, 403, 'You cannot decide your own travel request.');
        abort_unless($this->workflow->canDecide($user, $trip), 403, 'You are not the approver for this travel request.');
        if ($trip->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This travel request has already been '.$trip->status.'.']);
        }

        $trip->update(['status' => $status, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $request->input('reason') ?: null]);
        activity('expenses')->performedOn($trip)->causedBy($user)->withProperties(['reason' => $trip->decision_note])->log("Travel request {$status}");
        $this->workflow->notifyEmployee($trip, $status);

        return response()->json(['data' => $this->present($trip->fresh('employee')), 'message' => 'Travel request '.$status.'.']);
    }

    private function present(TravelRequest $t): array
    {
        return [
            'id' => $t->id, 'destination' => $t->destination, 'purpose' => $t->purpose,
            'departure_date' => $t->departure_date?->toDateString(), 'return_date' => $t->return_date?->toDateString(),
            'estimated_cost' => (float) $t->estimated_cost, 'notes' => $t->notes, 'status' => $t->status,
            'decision_note' => $t->decision_note, 'decided_at' => $t->decided_at, 'created_at' => $t->created_at,
            'employee_id' => $t->employee_id, 'employee_name' => $t->employee?->full_name,
            'photo' => $t->employee?->photo_url, 'department' => $t->employee?->department?->name,
            'can_decide' => $t->status === 'pending',
        ];
    }
}
