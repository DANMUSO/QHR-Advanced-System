<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\AssetAssignment;
use App\Modules\Recruitment\Models\InductionTask;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\OnboardingTask;
use App\Modules\Recruitment\Models\Posting;
use App\Modules\Recruitment\Models\Requisition;
use App\Modules\Recruitment\Services\ApplicantConversion;
use App\Models\User;
use App\Support\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RecruitmentController extends Controller
{
    // ── Requisitions ─────────────────────────────────────────────────────────────────────────

    public function requisitions(Request $request): JsonResponse
    {
        $page = Requisition::with(['position:id,title', 'department:id,name', 'requester:id,name', 'approver:id,name'])
            ->when(! $request->user()->can('recruitment.manage'), fn ($q) => $q->where('requested_by', $request->user()->id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('requisition_number', 'like', "%{$s}%")
                ->orWhereHas('position', fn ($p) => $p->where('title', 'like', "%{$s}%"))))
            ->latest()->paginate(25);

        return response()->json($page->through(fn (Requisition $r) => [
            'id' => $r->id, 'requisition_number' => $r->requisition_number,
            'position_id' => $r->position_id, 'position_title' => $r->position?->title,
            'department_id' => $r->department_id, 'department' => $r->department?->name,
            'vacancies' => $r->vacancies, 'type' => $r->type, 'justification' => $r->justification,
            'status' => $r->status, 'requested_by' => $r->requester?->name, 'requested_by_id' => $r->requested_by,
            'approved_by' => $r->approver?->name, 'approved_at' => $r->approved_at, 'decision_note' => $r->decision_note,
            'can_decide' => $r->status === 'pending_approval' && $this->canDecideRequisition($request->user(), $r),
            'created_at' => $r->created_at,
        ]));
    }

    public function approveRequisition(Request $request, int $id): JsonResponse
    {
        return $this->decideRequisition($request, $id, 'approve');
    }

    public function rejectRequisition(Request $request, int $id): JsonResponse
    {
        return $this->decideRequisition($request, $id, 'reject');
    }

    /** Someone other than the requester (anyone with recruitment.manage) approves or rejects a requisition. */
    private function decideRequisition(Request $request, int $id, string $decision): JsonResponse
    {
        $req = Requisition::findOrFail($id);
        abort_unless($req->status === 'pending_approval', 422, 'Only requisitions pending approval can be decided.');
        abort_unless($this->canDecideRequisition($request->user(), $req), 403, 'The requester cannot approve their own requisition.');
        // The dashboard inbox posts {reason}; the pipeline posts {note}.
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500'], 'reason' => ['nullable', 'string', 'max:500']]);
        $note = $data['note'] ?? $data['reason'] ?? null;
        abort_if($decision === 'reject' && ! $note, 422, 'Give a reason for rejecting the requisition.');

        $req->forceFill([
            'status' => $decision === 'approve' ? 'approved' : 'rejected',
            'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_note' => $note,
        ])->save();
        activity('recruitment')->performedOn($req)->causedBy($request->user())
            ->withProperties(['decision' => $decision, 'note' => $note])->log("Requisition {$req->requisition_number} {$req->status}");

        return response()->json(['data' => $req->fresh(), 'message' => "Requisition {$req->requisition_number} {$req->status}."]);
    }

    /**
     * The requester never decides their own requisition — not even a super admin. The id comparison comes first,
     * before any permission check, so a Gate::before super-admin bypass cannot short-circuit it.
     */
    public static function canDecideRequisition(User $user, Requisition $req): bool
    {
        if ($req->requested_by !== null && (int) $req->requested_by === (int) $user->id) {
            return false;
        }

        return $user->can('recruitment.manage');
    }

    /** A requester (without recruitment.manage) may only change their own requisition while it is not yet approved. */
    private function assertOwnRequisition(Request $request, Requisition $req): void
    {
        if (! $request->user()->can('recruitment.manage')) {
            abort_unless((int) $req->requested_by === (int) $request->user()->id, 403, 'You can only change your own requisitions.');
        }
    }

    public function storeRequisition(Request $request): JsonResponse
    {
        $data = $this->validateRequisition($request);
        $position = Position::findOrFail($data['position_id']);
        $req = new Requisition($data + ['status' => 'pending_approval', 'requested_by' => $request->user()->id]);
        $req->company_id = $position->company_id;
        $req->requisition_number = $this->nextNumber('REQ', Requisition::class, $position->company_id);
        $req->save();

        return response()->json(['data' => $req, 'message' => "Requisition {$req->requisition_number} submitted."], 201);
    }

    public function updateRequisition(Request $request, int $id): JsonResponse
    {
        $req = Requisition::findOrFail($id);
        $this->assertOwnRequisition($request, $req);
        abort_if(in_array($req->status, ['approved', 'filled'], true), 422, 'Approved requisitions cannot be edited.');
        $data = $this->validateRequisition($request, true);
        if ($req->status === 'rejected' && empty($data['status'])) {
            $data['status'] = 'pending_approval'; // editing a rejected requisition resubmits it
        }
        $req->update($data);

        return response()->json(['data' => $req->fresh(), 'message' => 'Requisition updated.']);
    }

    public function destroyRequisition(Request $request, int $id): JsonResponse
    {
        $req = Requisition::findOrFail($id);
        $this->assertOwnRequisition($request, $req);
        abort_if(Posting::where('requisition_id', $req->id)->exists(), 422, 'This requisition has job postings. Close the postings instead of deleting it.');
        $req->delete();

        return response()->json(['message' => 'Requisition deleted.']);
    }

    private function validateRequisition(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'position_id' => [$updating ? 'sometimes' : 'required', 'integer', $this->existsInCompany(Position::class)],
            'department_id' => [$updating ? 'sometimes' : 'required', 'integer', $this->existsInCompany(Department::class)],
            'vacancies' => [$updating ? 'sometimes' : 'required', 'integer', 'min:1', 'max:500'],
            'type' => [$updating ? 'sometimes' : 'required', Rule::in(Requisition::TYPES)],
            'justification' => ['nullable', 'string', 'max:2000'],
            // Approval goes through approve / reject; here the requester can only save a draft or (re)submit.
            'status' => ['sometimes', Rule::in(['draft', 'pending_approval'])],
        ], [
            'position_id.*' => 'Choose a valid position.',
            'department_id.*' => 'Choose a valid department.',
            'vacancies.min' => 'At least one vacancy is required.',
        ]);
    }

    // ── Job postings ─────────────────────────────────────────────────────────────────────────

    public function postings(Request $request): JsonResponse
    {
        $page = Posting::with('department:id,name')->withCount('applicants')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(25);

        return response()->json($page->through(fn (Posting $p) => [
            'id' => $p->id, 'title' => $p->title, 'department_id' => $p->department_id, 'department' => $p->department?->name,
            'location' => $p->location, 'closing_date' => $p->closing_date?->toDateString(), 'description' => $p->description,
            'requirements' => $p->requirements, 'status' => $p->closing_date && $p->closing_date->isPast() && $p->status === 'published' ? 'closed' : $p->status,
            'requisition_id' => $p->requisition_id, 'slug' => $p->slug,
            'applications' => $p->applicants_count, 'public_url' => $p->status === 'published' ? url('/careers/'.$p->slug) : null,
        ]));
    }

    public function storePosting(Request $request): JsonResponse
    {
        $data = $this->validatePosting($request);
        if (! empty($data['requisition_id'])) {
            $data['department_id'] ??= Requisition::find($data['requisition_id'])?->department_id;
        }
        $companyId = $this->companyFor($request, $data['department_id'] ?? null, Department::class);
        $posting = new Posting($data + ['status' => 'published', 'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(6))]);
        $posting->company_id = $companyId;
        $posting->save();

        return response()->json(['data' => $posting, 'message' => 'Job posted.'], 201);
    }

    public function updatePosting(Request $request, int $id): JsonResponse
    {
        $posting = Posting::findOrFail($id);
        $posting->update($this->validatePosting($request, true));

        return response()->json(['data' => $posting->fresh(), 'message' => 'Posting updated.']);
    }

    public function destroyPosting(int $id): JsonResponse
    {
        $posting = Posting::withCount('applicants')->findOrFail($id);
        abort_if($posting->applicants_count > 0, 422, 'This posting has applicants. Close it instead of deleting it.');
        $posting->delete();

        return response()->json(['message' => 'Posting deleted.']);
    }

    private function validatePosting(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:200'],
            'department_id' => ['nullable', 'integer', $this->existsInCompany(Department::class)],
            'requisition_id' => ['nullable', 'integer', $this->existsInCompany(Requisition::class), function ($attribute, $value, $fail) {
                // A posting for a requisition needs that requisition approved first.
                if ($value && Requisition::whereKey($value)->value('status') !== 'approved') {
                    $fail('The requisition must be approved before the job can be posted.');
                }
            }],
            'location' => ['nullable', 'string', 'max:150'],
            'closing_date' => ['nullable', 'date', $updating ? 'date' : 'after_or_equal:today'],
            'description' => ['nullable', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(Posting::STATUSES)],
        ]);
    }

    // ── Applicants ───────────────────────────────────────────────────────────────────────────

    public function applicants(Request $request): JsonResponse
    {
        $page = Applicant::with('posting:id,title')
            ->when($request->query('stage'), fn ($q, $s) => $q->where('stage', $s))
            ->when($request->query('posting_id'), fn ($q, $p) => $q->where('posting_id', $p))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->latest('applied_at')->paginate(min(500, max(10, $request->integer('per_page', 25))));

        return response()->json($page->through(fn (Applicant $a) => $this->presentApplicant($a)));
    }

    public function storeApplicant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'posting_id' => ['required', 'integer', $this->existsInCompany(Posting::class)],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $posting = Posting::findOrFail($data['posting_id']);
        $applicant = new Applicant($data + ['stage' => 'applied', 'applied_at' => now()]);
        $applicant->company_id = $posting->company_id;
        $applicant->save();

        return response()->json(['data' => $this->presentApplicant($applicant), 'message' => 'Applicant added.'], 201);
    }

    public function updateApplicant(Request $request, int $id): JsonResponse
    {
        $applicant = Applicant::findOrFail($id);
        $applicant->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'], 'email' => ['sometimes', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'], 'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]));

        return response()->json(['data' => $this->presentApplicant($applicant->fresh()), 'message' => 'Applicant updated.']);
    }

    /**
     * PATCH /recruitment/applicants/{id}/stage {stage, reason?} — advance an applicant, reject them (with a reason) or
     * mark an applicant holding an offer as hired. The offer stage is reached by creating an offer, not from here.
     */
    public function moveStage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(Applicant::STAGES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $applicant = Applicant::with('posting')->findOrFail($id);
        $to = $data['stage'];
        $reason = trim((string) ($data['reason'] ?? ''));

        abort_if($applicant->stage === 'hired', 422, "{$applicant->name} has already been hired.");
        abort_if($to === $applicant->stage, 422, "{$applicant->name} is already at the {$to} stage.");
        abort_if($to === 'offer', 422, 'Create an offer from the Offers tab to move an applicant to the offer stage.');
        abort_if($to === 'hired' && $applicant->stage !== 'offer', 422, 'Only an applicant holding an offer can be marked hired.');
        abort_if($to === 'rejected' && $reason === '', 422, 'Give a reason for rejecting the applicant.');

        if ($to === 'hired') {
            $this->hire($applicant);
        } else {
            $notes = $to === 'rejected' ? trim(($applicant->notes ? $applicant->notes."\n" : '').'Rejected '.now()->toDateString().': '.$reason) : $applicant->notes;
            $applicant->update(['stage' => $to, 'notes' => $notes]);
        }
        activity('recruitment')->performedOn($applicant)->causedBy($request->user())
            ->withProperties(array_filter(['stage' => $to, 'reason' => $reason ?: null]))->log("Applicant moved to {$to}");

        return response()->json(['data' => $this->presentApplicant($applicant->fresh()), 'message' => 'Moved to '.str_replace('_', ' ', $to).'.']);
    }

    /** GET /recruitment/applicants/{id} — the applicant with their interviews, offers and CV. */
    public function showApplicant(int $id): JsonResponse
    {
        $a = Applicant::with(['posting:id,title', 'interviews.interviewer:id,first_name,last_name', 'offers'])->findOrFail($id);

        return response()->json(['data' => $this->presentApplicant($a) + [
            'interviews' => $a->interviews->sortByDesc('scheduled_at')->values()->map(fn (Interview $i) => [
                'id' => $i->id, 'interview_type' => $i->interview_type, 'scheduled_at' => $i->scheduled_at?->format('Y-m-d H:i'),
                'interviewer_name' => $i->interviewer?->full_name, 'location' => $i->location, 'status' => $i->status,
                'score' => $i->score, 'feedback' => $i->feedback,
            ]),
            'offers' => $a->offers->sortByDesc('id')->values()->map(fn (Offer $o) => [
                'id' => $o->id, 'offered_salary' => (float) $o->offered_salary, 'start_date' => $o->start_date?->toDateString(),
                'expiry_date' => $o->expiry_date?->toDateString(), 'status' => $o->effectiveStatus(), 'sent_at' => $o->sent_at,
                'pdf_url' => url("/api/recruitment/offers/{$o->id}/pdf"),
            ]),
        ]]);
    }

    /** Marks the applicant hired, refusing once the requisition's vacancies are all filled, and fills the requisition on the last hire. */
    private function hire(Applicant $applicant): void
    {
        $req = $this->requisitionFor($applicant);
        if ($req) {
            $hired = Applicant::where('posting_id', $applicant->posting_id)->where('stage', 'hired')->whereKeyNot($applicant->id)->count();
            abort_if($hired >= $req->vacancies, 422, "All {$req->vacancies} vacancies on {$req->requisition_number} are already filled.");
        }
        $applicant->update(['stage' => 'hired', 'hired_at' => $applicant->hired_at ?? now()]);
        if ($req && $req->status === 'approved' && $hired + 1 >= $req->vacancies) {
            $req->update(['status' => 'filled']);
        }
    }

    private function requisitionFor(Applicant $applicant): ?Requisition
    {
        $reqId = $applicant->posting?->requisition_id;

        return $reqId ? Requisition::find($reqId) : null;
    }

    /** Refuses an offer unless the applicant has reached interview stage, is not hired and holds no accepted or open offer. */
    private function assertCanOffer(Applicant $applicant, ?int $ignoreOfferId = null): void
    {
        abort_if($applicant->employee_id || $applicant->stage === 'hired', 422, "{$applicant->name} has already been hired.");
        $offers = $applicant->offers()->when($ignoreOfferId, fn ($q) => $q->whereKeyNot($ignoreOfferId))->get();
        abort_if($offers->contains('status', 'accepted'), 422, "{$applicant->name} has already accepted an offer.");
        abort_if($offers->contains(fn (Offer $o) => in_array($o->effectiveStatus(), ['draft', 'sent'], true)), 422, "{$applicant->name} already has an open offer. Edit that offer instead.");
        abort_unless(in_array($applicant->stage, Applicant::OFFERABLE_STAGES, true), 422,
            "Offers can only be made to applicants at the interview stage or later ({$applicant->name} is at {$applicant->stage}).");
        if ($req = $this->requisitionFor($applicant)) {
            $hired = Applicant::where('posting_id', $applicant->posting_id)->where('stage', 'hired')->count();
            abort_if($hired >= $req->vacancies || $req->status === 'filled', 422, "All {$req->vacancies} vacancies on {$req->requisition_number} are already filled.");
        }
    }
    public function destroyApplicant(int $id): JsonResponse
    {
        Applicant::findOrFail($id)->delete();

        return response()->json(['message' => 'Applicant removed.']);
    }

    private function presentApplicant(Applicant $a): array
    {
        return [
            'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'phone' => $a->phone, 'photo' => null, 'photo_url' => null,
            'posting_id' => $a->posting_id, 'posting_title' => $a->posting?->title, 'stage' => $a->stage, 'score' => $a->score,
            'source' => $a->source, 'notes' => $a->notes, 'applied_at' => $a->applied_at?->toDateString(),
            'cover_letter' => $a->cover_letter, 'cv_url' => $a->cv_path ? url("/api/recruitment/applicants/{$a->id}/cv") : null,
            'employee_id' => $a->employee_id, 'can_convert' => ApplicantConversion::canConvert($a),
            // Stage actions the pipeline offers: advance one step, reject (with a reason), or make an offer.
            'next_stage' => ['applied' => 'screening', 'screening' => 'interview', 'rejected' => 'screening'][$a->stage] ?? null,
            'can_reject' => ! in_array($a->stage, ['hired', 'rejected'], true),
            'can_offer' => in_array($a->stage, Applicant::OFFERABLE_STAGES, true) && ! $a->employee_id,
        ];
    }

    /** GET /recruitment/applicants/{id}/conversion — onboarding form prefill for a hired applicant. */
    public function conversion(int $id): JsonResponse
    {
        $applicant = Applicant::with('posting')->findOrFail($id);
        abort_if($applicant->employee_id, 422, "{$applicant->name} has already been converted to an employee.");
        abort_unless(ApplicantConversion::canConvert($applicant), 422, 'Only hired applicants (or accepted offers) can be converted to employees.');

        return response()->json(['data' => app(ApplicantConversion::class)->prefill($applicant)]);
    }

    /** GET /recruitment/applicants/{id}/cv — the CV uploaded through the careers page. */
    public function applicantCv(int $id): Response
    {
        $applicant = Applicant::findOrFail($id);
        abort_unless($applicant->cv_path && Storage::disk('local')->exists($applicant->cv_path), 404, 'No CV on file.');

        return Storage::disk('local')->download($applicant->cv_path, Str::slug($applicant->name).'-cv.'.pathinfo($applicant->cv_path, PATHINFO_EXTENSION));
    }

    // ── Interviews ───────────────────────────────────────────────────────────────────────────

    public function interviews(Request $request): JsonResponse
    {
        $page = Interview::with(['applicant.posting:id,title', 'interviewer:id,first_name,last_name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('scheduled_at')->paginate(25);

        return response()->json($page->through(fn (Interview $i) => [
            'id' => $i->id, 'applicant_id' => $i->applicant_id, 'applicant_name' => $i->applicant?->name,
            'position' => $i->applicant?->posting?->title, 'interview_type' => $i->interview_type,
            'scheduled_at' => $i->scheduled_at?->format('Y-m-d H:i'), 'interviewer_id' => $i->interviewer_id,
            'interviewer_name' => $i->interviewer?->full_name, 'location' => $i->location,
            'status' => $i->status, 'score' => $i->score, 'feedback' => $i->feedback,
        ]));
    }

    public function storeInterview(Request $request): JsonResponse
    {
        $data = $this->validateInterview($request);
        $applicant = Applicant::findOrFail($data['applicant_id']);
        abort_if(in_array($applicant->stage, ['hired', 'rejected'], true), 422, "{$applicant->name} is {$applicant->stage}; interviews can no longer be scheduled.");
        $interview = new Interview(['status' => 'scheduled'] + $data);
        $this->assertInterviewOutcomeAllowed($interview);
        $interview->company_id = $applicant->company_id;
        $interview->save();
        if (in_array($applicant->stage, ['applied', 'screening'], true)) {
            $applicant->update(['stage' => 'interview']);
        }
        $this->syncInterviewOutcome($interview);

        return response()->json(['data' => $interview, 'message' => 'Interview scheduled.'], 201);
    }

    public function updateInterview(Request $request, int $id): JsonResponse
    {
        $interview = Interview::findOrFail($id);
        $interview->fill($this->validateInterview($request, true));
        $this->assertInterviewOutcomeAllowed($interview);
        $interview->save();
        $this->syncInterviewOutcome($interview);

        return response()->json(['data' => $interview->fresh(), 'message' => 'Interview updated.']);
    }

    /** An interview can only be completed (or marked a no-show) once its scheduled date and time has passed. */
    private function assertInterviewOutcomeAllowed(Interview $interview): void
    {
        if (in_array($interview->status, ['completed', 'no_show'], true) && $interview->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages(['status' => 'This interview is scheduled for '.$interview->scheduled_at->format('j M Y H:i').'; it cannot be '.($interview->status === 'completed' ? 'completed' : 'marked a no-show').' before then.']);
        }
    }

    /**
     * A completed interview moves an applicant still at applied / screening to the interview stage and stores the
     * applicant's score as the average of their completed interview scores (shown in the Applicants list).
     */
    private function syncInterviewOutcome(Interview $interview): void
    {
        if ($interview->status !== 'completed' || ! ($applicant = Applicant::find($interview->applicant_id))) {
            return;
        }
        $changes = [];
        if (in_array($applicant->stage, ['applied', 'screening'], true)) {
            $changes['stage'] = 'interview';
        }
        $avg = Interview::where('applicant_id', $applicant->id)->where('status', 'completed')->whereNotNull('score')->avg('score');
        if ($avg !== null) {
            $changes['score'] = (int) round($avg);
        }
        if ($changes) {
            $applicant->update($changes);
        }
    }

    public function destroyInterview(int $id): JsonResponse
    {
        Interview::findOrFail($id)->delete();

        return response()->json(['message' => 'Interview deleted.']);
    }

    private function validateInterview(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'applicant_id' => [$updating ? 'sometimes' : 'required', 'integer', $this->existsInCompany(Applicant::class)],
            'interview_type' => [$updating ? 'sometimes' : 'required', Rule::in(Interview::TYPES)],
            'scheduled_at' => [$updating ? 'sometimes' : 'required', 'date'],
            'interviewer_id' => ['nullable', 'integer', $this->existsInCompany(Employee::class)],
            'location' => ['nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(Interview::STATUSES)],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ], ['applicant_id.*' => 'Choose a valid applicant.', 'interviewer_id.*' => 'Choose a valid interviewer.']);
    }

    // ── Offers ───────────────────────────────────────────────────────────────────────────────

    public function offers(Request $request): JsonResponse
    {
        $page = Offer::with('applicant.posting:id,title')->latest()->paginate(25);

        return response()->json($page->through(fn (Offer $o) => [
            'id' => $o->id, 'applicant_id' => $o->applicant_id, 'applicant_name' => $o->applicant?->name,
            'position' => $o->applicant?->posting?->title, 'offered_salary' => (float) $o->offered_salary,
            'start_date' => $o->start_date?->toDateString(), 'expiry_date' => $o->expiry_date?->toDateString(),
            'notes' => $o->notes, 'status' => $o->effectiveStatus(),
            'sent_at' => $o->sent_at, 'responded_at' => $o->responded_at,
            'applicant_stage' => $o->applicant?->stage, 'employee_id' => $o->applicant?->employee_id,
            'can_convert' => $o->status === 'accepted' && $o->applicant && ! $o->applicant->employee_id,
            'pdf_url' => url("/api/recruitment/offers/{$o->id}/pdf"),
        ]));
    }

    public function storeOffer(Request $request): JsonResponse
    {
        $data = $this->validateOffer($request);
        $applicant = Applicant::with('posting')->findOrFail($data['applicant_id']);
        $this->assertCanOffer($applicant);
        // Every offer starts as a draft; "Mark as sent to the candidate now" then runs the same Send transition.
        $sendNow = ($data['status'] ?? 'draft') === 'sent';
        unset($data['status']);
        $offer = new Offer(['status' => 'draft', 'created_by' => $request->user()->id] + $data);
        $offer->company_id = $applicant->company_id;
        $offer->save();
        $applicant->update(['stage' => 'offer']);
        if ($sendNow) {
            $this->transitionOffer($offer, 'sent', $request->user());
        }

        return response()->json(['data' => $offer->fresh(), 'message' => $sendNow ? 'Offer created and sent.' : 'Offer created.'], 201);
    }

    public function updateOffer(Request $request, int $id): JsonResponse
    {
        $offer = Offer::findOrFail($id);
        $data = $this->validateOffer($request, true);
        $status = $data['status'] ?? null;
        unset($data['status'], $data['applicant_id']); // an offer stays with its applicant
        abort_if($data && ! in_array($offer->status, ['draft', 'sent'], true), 422, 'An offer that was accepted or declined cannot be edited.');
        $offer->update($data);
        if ($status && $status !== $offer->status) {
            return $this->offerStatus($request, $id);
        }

        return response()->json(['data' => $offer->fresh(), 'message' => 'Offer updated.']);
    }

    /** POST /recruitment/offers/{id}/status {status} — draft → sent → accepted | declined (an expired offer may be re-sent). */
    public function offerStatus(Request $request, int $id): JsonResponse
    {
        $offer = Offer::with('applicant.posting')->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(['sent', 'accepted', 'declined'])]]);
        $this->transitionOffer($offer, $data['status'], $request->user());

        return response()->json(['data' => $offer->fresh(), 'message' => 'Offer marked '.$data['status'].'.']);
    }

    /** draft → sent → accepted | declined; an expired offer may be re-sent or declined. Accepting hires the applicant. */
    private function transitionOffer(Offer $offer, string $status, User $user): void
    {
        $current = $offer->effectiveStatus();
        $allowed = ['draft' => ['sent'], 'sent' => ['accepted', 'declined'], 'expired' => ['sent', 'declined']][$current] ?? [];
        abort_unless(in_array($status, $allowed, true), 422, $current === 'expired' && $status === 'accepted'
            ? 'This offer has expired. Extend the expiry date and re-send it first.'
            : "A {$current} offer cannot be marked {$status}.");
        abort_if($status === 'sent' && $offer->isExpired(), 422, 'Set an expiry date in the future before sending the offer.');

        if ($status === 'accepted' && $offer->applicant) {
            $this->hire($offer->applicant); // refuses when every vacancy is already filled
        }
        $offer->forceFill(['status' => $status] + ($status === 'sent' ? ['sent_at' => now()] : ['responded_at' => now()]))->save();
        activity('recruitment')->performedOn($offer)->causedBy($user)->withProperties(['status' => $status])->log("Offer {$status}");
    }

    public function destroyOffer(int $id): JsonResponse
    {
        Offer::findOrFail($id)->delete();

        return response()->json(['message' => 'Offer deleted.']);
    }

    public function offerPdf(int $id): Response
    {
        $offer = Offer::with('applicant.posting.department', 'company')->findOrFail($id);
        $html = view('recruitment::offer', ['offer' => $offer])->render();

        return Pdf::loadHTML($html)->stream("offer-{$offer->id}.pdf");
    }

    private function validateOffer(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'applicant_id' => [$updating ? 'sometimes' : 'required', 'integer', $this->existsInCompany(Applicant::class)],
            'offered_salary' => [$updating ? 'sometimes' : 'required', 'numeric', 'min:1', 'max:100000000'],
            'start_date' => [$updating ? 'sometimes' : 'required', 'date'],
            'expiry_date' => [$updating ? 'sometimes' : 'required', 'date', $updating ? 'date' : 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(Offer::STATUSES)],
        ], ['applicant_id.*' => 'Choose a valid applicant.']);
    }

    // ── Induction task templates ─────────────────────────────────────────────────────────────

    public function induction(): JsonResponse
    {
        return response()->json(['data' => InductionTask::orderBy('due_days')->orderBy('name')->get()]);
    }

    public function storeInduction(Request $request): JsonResponse
    {
        $task = new InductionTask($this->validateInduction($request));
        $task->company_id = $this->companyFor($request);
        $task->save();

        return response()->json(['data' => $task, 'message' => 'Induction task added.'], 201);
    }

    public function updateInduction(Request $request, int $id): JsonResponse
    {
        $task = InductionTask::findOrFail($id);
        $task->update($this->validateInduction($request, true));

        return response()->json(['data' => $task->fresh(), 'message' => 'Induction task updated.']);
    }

    public function destroyInduction(int $id): JsonResponse
    {
        InductionTask::findOrFail($id)->delete();

        return response()->json(['message' => 'Induction task deleted.']);
    }

    private function validateInduction(Request $request, bool $updating = false): array
    {
        $data = $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:150'],
            'category' => [$updating ? 'sometimes' : 'required', Rule::in(InductionTask::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_mandatory' => ['boolean'],
            'owner_role' => ['nullable', 'string', 'max:30'],
        ]);
        if (! $updating) {
            $data['due_days'] ??= 1;
            $data['owner_role'] ??= $data['category'];
        }

        return $data;
    }

    // ── Onboarding checklists ────────────────────────────────────────────────────────────────

    /** New hires from the last 90 days, each with a checklist generated from the induction templates. */
    public function onboarding(): JsonResponse
    {
        // Checklists are created when the employee is hired (EmployeeHired → OnboardingChecklist), not on read.
        $hires = Employee::active()->where('date_of_joining', '>=', now()->subDays(90))
            ->with('position:id,title')->orderByDesc('date_of_joining')->get();

        $tasks = OnboardingTask::whereIn('employee_id', $hires->pluck('id'))->orderBy('due_date')->get()->groupBy('employee_id');

        return response()->json(['data' => $hires->map(function (Employee $e) use ($tasks) {
            $list = $tasks[$e->id] ?? collect();
            $done = $list->whereNotNull('completed_at')->count();

            return [
                'id' => $e->id, 'name' => $e->full_name, 'photo_url' => $e->photo_url, 'position' => $e->position?->title,
                'start_date' => $e->date_of_joining?->toDateString(), 'total_tasks' => $list->count(), 'completed_tasks' => $done,
                'progress' => $list->count() ? (int) round($done / $list->count() * 100) : 0,
                'tasks' => $list->map(fn (OnboardingTask $t) => [
                    'id' => $t->id, 'name' => $t->name, 'category' => $t->category, 'owner' => strtoupper($t->category), 'is_mandatory' => $t->is_mandatory,
                    'due_date' => $t->due_date?->toDateString(), 'completed' => (bool) $t->completed_at,
                    'completed_at' => $t->completed_at, 'overdue' => ! $t->completed_at && $t->due_date?->isPast(),
                ])->values(),
            ];
        })->filter(fn ($row) => $row['total_tasks'] > 0)->values()]);
    }

    public function completeTask(Request $request, int $employee, int $task): JsonResponse
    {
        $t = OnboardingTask::where('employee_id', $employee)->findOrFail($task);
        $t->update(['completed_at' => $t->completed_at ? null : now(), 'completed_by' => $t->completed_at ? null : $request->user()->id]);

        return response()->json(['message' => $t->completed_at ? 'Task completed.' : 'Task reopened.']);
    }

    // ── Assets ───────────────────────────────────────────────────────────────────────────────

    public function assets(Request $request): JsonResponse
    {
        $page = AssetAssignment::with('employee:id,first_name,last_name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('assigned_date')->paginate(25);

        return response()->json($page->through(fn (AssetAssignment $a) => [
            'id' => $a->id, 'employee_id' => $a->employee_id, 'employee_name' => $a->employee?->full_name,
            'asset_name' => $a->asset_name, 'asset_tag' => $a->asset_tag,
            'assigned_date' => $a->assigned_date?->toDateString(), 'return_date' => $a->return_date?->toDateString(),
            'status' => $a->status, 'notes' => $a->notes,
        ]));
    }

    public function storeAsset(Request $request): JsonResponse
    {
        $data = $this->validateAsset($request);
        $employee = Employee::findOrFail($data['employee_id']);
        $asset = new AssetAssignment($data + ['status' => 'assigned']);
        $asset->company_id = $employee->company_id;
        $asset->save();

        return response()->json(['data' => $asset, 'message' => 'Asset assigned.'], 201);
    }

    public function updateAsset(Request $request, int $id): JsonResponse
    {
        $asset = AssetAssignment::findOrFail($id);
        $data = $this->validateAsset($request, true);
        if (($data['status'] ?? null) === 'returned' && empty($data['return_date']) && ! $asset->return_date) {
            $data['return_date'] = now()->toDateString();
        }
        $asset->update($data);

        return response()->json(['data' => $asset->fresh(), 'message' => 'Asset updated.']);
    }

    public function destroyAsset(int $id): JsonResponse
    {
        AssetAssignment::findOrFail($id)->delete();

        return response()->json(['message' => 'Asset record deleted.']);
    }

    private function validateAsset(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'employee_id' => [$updating ? 'sometimes' : 'required', 'integer', $this->existsInCompany(Employee::class)],
            'asset_name' => [$updating ? 'sometimes' : 'required', 'string', 'max:150'],
            'asset_tag' => ['nullable', 'string', 'max:60'],
            'assigned_date' => [$updating ? 'sometimes' : 'required', 'date'],
            'return_date' => ['nullable', 'date', 'after_or_equal:assigned_date'],
            'status' => ['sometimes', Rule::in(AssetAssignment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['employee_id.*' => 'Choose a valid employee.']);
    }

    // ── Reports ──────────────────────────────────────────────────────────────────────────────

    public function reports(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reportRows($request)]);
    }

    public function exportReport(Request $request): Response
    {
        $rows = $this->reportRows($request);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Position', 'Department', 'Applications', 'Screened', 'Interviewed', 'Offers made', 'Hired', 'Avg. days to hire']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['position'], $r['department'], $r['applications'], $r['screened'], $r['interviewed'], $r['offers_made'], $r['hired'], $r['time_to_hire']]);
            }
            fclose($out);
        }, 'hiring-report-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function reportRows(Request $request): array
    {
        $year = $request->integer('year') ?: null;
        $postings = Posting::with('department:id,name')
            ->with(['applicants' => fn ($q) => $year ? $q->whereYear('applied_at', $year) : $q])
            ->get();
        $offerApplicants = Offer::pluck('applicant_id')->flip();
        $interviewed = Interview::pluck('applicant_id')->flip();

        return $postings->map(function (Posting $p) use ($offerApplicants, $interviewed) {
            $apps = $p->applicants;
            $hired = $apps->where('stage', 'hired');
            $days = $hired->filter(fn ($a) => $a->hired_at)->map(fn ($a) => $a->applied_at->diffInDays($a->hired_at));

            return [
                'id' => $p->id, 'position' => $p->title, 'department' => $p->department?->name ?? '—',
                'applications' => $apps->count(),
                'screened' => $apps->whereNotIn('stage', ['applied'])->count(),
                'interviewed' => $apps->filter(fn ($a) => isset($interviewed[$a->id]) || in_array($a->stage, ['interview', 'offer', 'hired'], true))->count(),
                'offers_made' => $apps->filter(fn ($a) => isset($offerApplicants[$a->id]))->count(),
                'hired' => $hired->count(),
                'time_to_hire' => $days->isEmpty() ? null : (int) round($days->avg()),
            ];
        })->sortByDesc('applications')->values()->all();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /** Validates that an id exists within the records visible to the current company scope. */
    private function existsInCompany(string $modelClass): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($modelClass) {
            if ($value !== null && ! $modelClass::query()->whereKey($value)->exists()) {
                $fail('The selected '.str_replace('_id', '', $attribute).' does not exist.');
            }
        };
    }

    private function companyFor(Request $request, ?int $refId = null, ?string $refClass = null): int
    {
        if ($refId && $refClass && ($ref = $refClass::find($refId))) {
            return $ref->company_id;
        }
        $companyId = Tenancy::companyIdForWrite($request->integer('company_id') ?: null);
        if (! $companyId) {
            throw ValidationException::withMessages(['company_id' => 'Choose a company (use the company switcher at the top).']);
        }

        return $companyId;
    }

    /** @param class-string<Model> $model */
    private function nextNumber(string $prefix, string $model, int $companyId): string
    {
        $count = $model::withoutGlobalScopes()->where('company_id', $companyId)->withTrashed()->count() + 1;
        do {
            $number = sprintf('%s-%d-%04d', $prefix, $companyId, $count++);
        } while ($model::withoutGlobalScopes()->where('requisition_number', $number)->exists());

        return $number;
    }
}
