<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Models\EmployeeLetter;
use App\Modules\Employees\Models\ExitRecord;
use App\Modules\Employees\Models\LetterRequest;
use App\Modules\Employees\Models\ProfileChangeRequest;
use App\Modules\Employees\Models\ResignationRequest;
use App\Modules\Employees\Services\LetterService;
use App\Modules\Employees\Services\SelfServiceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My Portal requests (sensitive profile changes, resignation, letters, my contracts) and the HR queue that decides them.
 * Employee endpoints live under /profile; HR endpoints under /staff-requests (employees.update).
 */
class SelfServiceController extends Controller
{
    public function __construct(private SelfServiceRequestService $service) {}

    private function me(Request $request): Employee
    {
        $e = $request->user()->employee;
        abort_unless($e, 404, 'Your account is not linked to an employee record.');

        return $e;
    }

    /* ================================================================ employee */

    /** GET /profile/requests: everything the employee has asked HR for, plus their issued letters. */
    public function mine(Request $request): JsonResponse
    {
        $e = $this->me($request);

        return response()->json([
            'profile_changes' => ProfileChangeRequest::where('employee_id', $e->id)->with('decider:id,name')->latest('id')->limit(20)->get()
                ->map(fn ($r) => $this->presentChange($r)),
            'resignations' => ResignationRequest::where('employee_id', $e->id)->latest('id')->get()->map(fn ($r) => $this->presentResignation($r)),
            'letter_requests' => LetterRequest::where('employee_id', $e->id)->with('letter')->latest('id')->get()->map(fn ($r) => $this->presentLetterRequest($r)),
            'letters' => EmployeeLetter::where('employee_id', $e->id)->latest('id')->get()->map(fn (EmployeeLetter $l) => [
                'id' => $l->id, 'reference' => $l->reference, 'subject' => $l->subject,
                'template_name' => LetterService::TEMPLATES[$l->template][0] ?? $l->template,
                'effective_date' => $l->effective_date?->toDateString(), 'generated_at' => $l->created_at,
                'path' => '/api/employees/letters/'.$l->id.'/download',
            ]),
            'letter_templates' => collect(LetterRequest::TEMPLATES)->map(fn ($k) => [
                'key' => $k, 'name' => LetterService::TEMPLATES[$k][0], 'description' => LetterService::TEMPLATES[$k][1],
            ]),
        ]);
    }

    /** POST /profile/change-requests: statutory numbers, next_of_kin and/or payment, all approved by HR. */
    public function requestChange(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $changes = $this->service->diff($e, $request->all());
        abort_if(! $changes, 422, 'Nothing has changed.');
        $this->service->validate($e, $changes);
        $r = $this->service->submitChange($e, $changes, $request->user(), $request->input('note'));

        return response()->json(['data' => $this->presentChange($r), 'message' => 'Sent to HR for approval.'], 201);
    }

    public function cancelChange(Request $request, int $id): JsonResponse
    {
        $r = ProfileChangeRequest::where('employee_id', $this->me($request)->id)->findOrFail($id);
        abort_unless($r->status === 'pending', 422, 'Only a pending request can be cancelled.');
        $r->update(['status' => 'cancelled', 'decided_at' => now(), 'decided_by' => $request->user()->id]);

        return response()->json(['message' => 'Request cancelled.']);
    }

    /** GET /profile/contracts: the signed-in employee's own contracts. */
    public function contracts(Request $request): JsonResponse
    {
        $e = $this->me($request);

        return response()->json(['data' => EmployeeContract::where('employee_id', $e->id)->orderByDesc('start_date')->get()
            ->map(fn (EmployeeContract $c) => [
                'id' => $c->id, 'reference' => $c->reference, 'job_title' => $c->job_title,
                'contract_type' => $c->contract_type, 'contract_type_label' => EmployeeContract::TYPES[$c->contract_type] ?? $c->contract_type,
                'start_date' => $c->start_date?->toDateString(), 'end_date' => $c->end_date?->toDateString(),
                'gross_salary' => $c->gross_salary !== null ? (float) $c->gross_salary : null, 'status' => $c->status,
                'path' => '/api/employees/contracts/'.$c->id.'/pdf',
            ])]);
    }

    public function resign(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $data = $request->validate([
            'notice_date' => ['nullable', 'date', 'before_or_equal:today'],
            'last_working_day' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:3000'],
            'letter' => ['nullable', 'file', 'max:5120', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ], ['last_working_day.after_or_equal' => 'Your last working day cannot be in the past.']);
        $r = $this->service->resign($e, $data, $request->file('letter'), $request->user());

        return response()->json(['data' => $this->presentResignation($r), 'message' => 'Your resignation has been sent to HR.'], 201);
    }

    public function withdrawResignation(Request $request, int $id): JsonResponse
    {
        $r = ResignationRequest::where('employee_id', $this->me($request)->id)->findOrFail($id);
        $this->service->withdrawResignation($r, $request->user());

        return response()->json(['message' => 'Resignation withdrawn.']);
    }

    /** The uploaded resignation letter: its author or HR. */
    public function resignationLetter(Request $request, int $id): StreamedResponse
    {
        $r = ResignationRequest::findOrFail($id);
        $user = $request->user();
        abort_unless($user->employee?->id === $r->employee_id || $user->can('employees.update'), 403);
        abort_unless($r->letter_path && Storage::disk('local')->exists($r->letter_path), 404);

        return Storage::disk('local')->download($r->letter_path, $r->letter_name ?? basename($r->letter_path));
    }

    public function requestLetter(Request $request): JsonResponse
    {
        $e = $this->me($request);
        $data = $request->validate([
            'template' => ['required', Rule::in(LetterRequest::TEMPLATES)],
            'addressed_to' => ['nullable', 'string', 'max:200'],
            'purpose' => ['required', 'string', 'min:5', 'max:2000'],
        ], ['template.required' => 'Choose the letter you need.', 'purpose.required' => 'Tell HR what the letter is for.']);
        abort_if(LetterRequest::where('employee_id', $e->id)->where('template', $data['template'])->where('status', 'pending')->exists(), 422,
            'You already have a pending request for this letter.');
        $r = $this->service->requestLetter($e, $data, $request->user());

        return response()->json(['data' => $this->presentLetterRequest($r), 'message' => 'Letter requested. HR will notify you when it is ready.'], 201);
    }

    public function cancelLetter(Request $request, int $id): JsonResponse
    {
        $r = LetterRequest::where('employee_id', $this->me($request)->id)->findOrFail($id);
        abort_unless($r->status === 'pending', 422, 'Only a pending request can be cancelled.');
        $r->update(['status' => 'cancelled', 'decided_at' => now(), 'decided_by' => $request->user()->id]);

        return response()->json(['message' => 'Request cancelled.']);
    }

    /* ================================================================ HR */

    public function summary(): JsonResponse
    {
        return response()->json(['data' => [
            'profile' => ProfileChangeRequest::where('status', 'pending')->count(),
            'resignations' => ResignationRequest::where('status', 'pending')->count(),
            'letters' => LetterRequest::where('status', 'pending')->count(),
        ]]);
    }

    public function profileChanges(Request $request): JsonResponse
    {
        $page = ProfileChangeRequest::query()->with(['employee:id,first_name,last_name,employee_number,photo_path', 'decider:id,name'])
            ->when($request->query('status', 'pending'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->latest('id')->paginate(min($request->integer('per_page', 25) ?: 25, 100));

        return response()->json($page->through(fn ($r) => $this->presentChange($r, true)));
    }

    public function approveChange(Request $request, int $id): JsonResponse
    {
        $r = $this->service->approveChange(ProfileChangeRequest::findOrFail($id), $request->user());

        return response()->json(['data' => $this->presentChange($r->fresh(['employee', 'decider']), true), 'message' => 'Change approved and applied.']);
    }

    public function rejectChange(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $r = $this->service->rejectChange(ProfileChangeRequest::findOrFail($id), $request->user(), $data['reason']);

        return response()->json(['data' => $this->presentChange($r, true), 'message' => 'Change rejected.']);
    }

    public function resignations(Request $request): JsonResponse
    {
        $page = ResignationRequest::query()->with(['employee:id,first_name,last_name,employee_number,photo_path,status,primary_supervisor_id', 'decider:id,name', 'exitRecord'])
            ->when($request->query('status', 'pending'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->latest('id')->paginate(min($request->integer('per_page', 25) ?: 25, 100));

        return response()->json($page->through(fn ($r) => $this->presentResignation($r, true)));
    }

    public function declineResignation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->service->declineResignation(ResignationRequest::findOrFail($id), $request->user(), $data['reason']);

        return response()->json(['message' => 'Resignation returned to the employee.']);
    }

    public function letterRequests(Request $request): JsonResponse
    {
        $page = LetterRequest::query()->with(['employee:id,first_name,last_name,employee_number,photo_path', 'decider:id,name', 'letter'])
            ->when($request->query('status', 'pending'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->latest('id')->paginate(min($request->integer('per_page', 25) ?: 25, 100));

        return response()->json($page->through(fn ($r) => $this->presentLetterRequest($r, true)));
    }

    public function issueLetter(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['effective_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $r = LetterRequest::findOrFail($id);
        $letter = $this->service->issueLetter($r, $request->user(), $data['effective_date'] ?? null, $data['notes'] ?? null);

        return response()->json([
            'data' => $this->presentLetterRequest($r->fresh(['employee', 'letter', 'decider']), true),
            'url' => url('/api/employees/letters/'.$letter->id.'/download'), 'message' => 'Letter issued.',
        ]);
    }

    public function rejectLetter(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->service->declineLetter(LetterRequest::findOrFail($id), $request->user(), $data['reason']);

        return response()->json(['message' => 'Letter request declined.']);
    }

    /* ================================================================ presenters */

    private function person(?Employee $e): ?array
    {
        return $e ? ['id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number, 'photo_url' => $e->photo_url] : null;
    }

    private function presentChange(ProfileChangeRequest $r, bool $hr = false): array
    {
        $changes = $r->changes ?? [];
        $previous = $r->previous ?? [];
        // Employees see their own new values; account numbers stay masked in lists either way except for HR reviewing.
        if (! $hr && isset($changes['payment']['account_number'])) {
            $changes['payment']['account_number'] = '••••'.substr((string) $changes['payment']['account_number'], -4);
        }

        return [
            'id' => $r->id, 'status' => $r->status, 'summary' => $r->summary(), 'note' => $r->note,
            'changes' => $changes, 'previous' => $hr ? $previous : null,
            'decision_note' => $r->decision_note, 'decided_by' => $r->decider?->name, 'decided_at' => $r->decided_at,
            'created_at' => $r->created_at,
            'employee' => $hr ? $this->person($r->employee) : null,
        ];
    }

    private function presentResignation(ResignationRequest $r, bool $hr = false): array
    {
        return [
            'id' => $r->id, 'status' => $r->status,
            'notice_date' => $r->notice_date?->toDateString(), 'last_working_day' => $r->last_working_day?->toDateString(),
            'reason' => $r->reason, 'letter_name' => $r->letter_name,
            'letter_path' => $r->letter_path ? '/api/profile/resignations/'.$r->id.'/letter' : null,
            'exit_id' => $r->exit_id,
            'exit' => $r->exitRecord ? ['id' => $r->exitRecord->id, 'status' => $r->exitRecord->status, 'exit_date' => $r->exitRecord->exit_date?->toDateString()] : null,
            // HR may have recorded the exit directly; "Process exit" must not be offered again.
            'has_exit' => (bool) $r->exit_id || ($hr && ExitRecord::where('employee_id', $r->employee_id)->whereIn('status', ['scheduled', 'completed'])->exists()),
            'decision_note' => $r->decision_note, 'decided_at' => $r->decided_at, 'created_at' => $r->created_at,
            'employee' => $hr ? ($this->person($r->employee) ?? []) + ['status' => $r->employee?->status] : null,
        ];
    }

    private function presentLetterRequest(LetterRequest $r, bool $hr = false): array
    {
        return [
            'id' => $r->id, 'status' => $r->status, 'template' => $r->template,
            'template_name' => LetterService::TEMPLATES[$r->template][0] ?? $r->template,
            'addressed_to' => $r->addressed_to, 'purpose' => $r->purpose,
            'decision_note' => $r->decision_note, 'decided_at' => $r->decided_at, 'created_at' => $r->created_at,
            'letter' => $r->letter ? ['id' => $r->letter->id, 'subject' => $r->letter->subject, 'reference' => $r->letter->reference,
                'path' => '/api/employees/letters/'.$r->letter->id.'/download'] : null,
            'employee' => $hr ? $this->person($r->employee) : null,
        ];
    }
}
