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

    public static function canDecideRequisition(User $user, Requisition $req): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        return (int) $req->requested_by !== (int) $user->id && $user->can('recruitment.manage');
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

    public function moveStage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['stage' => ['required', Rule::in(Applicant::STAGES)]]);
        $applicant = Applicant::findOrFail($id);
        $applicant->update(['stage' => $data['stage'], 'hired_at' => $data['stage'] === 'hired' ? now() : $applicant->hired_at]);

        if ($data['stage'] === 'hired') {
            $filled = Applicant::where('posting_id', $applicant->posting_id)->where('stage', 'hired')->count();
            $posting = $applicant->posting;
            if ($posting?->requisition_id && ($req = Requisition::find($posting->requisition_id)) && $req->status === 'approved' && $filled >= $req->vacancies) {
                $req->update(['status' => 'filled']);
            }
        }

        return response()->json(['data' => $this->presentApplicant($applicant->fresh()), 'message' => 'Moved to '.str_replace('_', ' ', $data['stage']).'.']);
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
        $interview = new Interview($data + ['status' => 'scheduled']);
        $interview->company_id = $applicant->company_id;
        $interview->save();
        if (in_array($applicant->stage, ['applied', 'screening'], true)) {
            $applicant->update(['stage' => 'interview']);
        }

        return response()->json(['data' => $interview, 'message' => 'Interview scheduled.'], 201);
    }

    public function updateInterview(Request $request, int $id): JsonResponse
    {
        $interview = Interview::findOrFail($id);
        $interview->update($this->validateInterview($request, true));

        return response()->json(['data' => $interview->fresh(), 'message' => 'Interview updated.']);
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
            'notes' => $o->notes, 'status' => $o->status === 'sent' && $o->expiry_date?->isPast() ? 'expired' : $o->status,
            'sent_at' => $o->sent_at, 'responded_at' => $o->responded_at,
            'applicant_stage' => $o->applicant?->stage, 'employee_id' => $o->applicant?->employee_id,
            'can_convert' => $o->status === 'accepted' && $o->applicant && ! $o->applicant->employee_id,
            'pdf_url' => url("/api/recruitment/offers/{$o->id}/pdf"),
        ]));
    }

    public function storeOffer(Request $request): JsonResponse
    {
        $data = $this->validateOffer($request);
        $applicant = Applicant::findOrFail($data['applicant_id']);
        // New offers start as drafts unless HR sends them straight away.
        $offer = new Offer(['status' => 'draft', 'created_by' => $request->user()->id] + $data);
        if (! in_array($offer->status, ['draft', 'sent'], true)) {
            $offer->status = 'draft';
        }
        $offer->sent_at = $offer->status === 'sent' ? now() : null;
        $offer->company_id = $applicant->company_id;
        $offer->save();
        $applicant->update(['stage' => 'offer']);

        return response()->json(['data' => $offer, 'message' => 'Offer created.'], 201);
    }

    public function updateOffer(Request $request, int $id): JsonResponse
    {
        $offer = Offer::findOrFail($id);
        $data = $this->validateOffer($request, true);
        $status = $data['status'] ?? null;
        unset($data['status']);
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
        $offer = Offer::with('applicant')->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(['sent', 'accepted', 'declined'])]]);
        $current = $offer->status === 'sent' && $offer->expiry_date?->isPast() ? 'expired' : $offer->status;
        $allowed = ['draft' => ['sent'], 'sent' => ['accepted', 'declined'], 'expired' => ['sent', 'declined']][$current] ?? [];
        abort_unless(in_array($data['status'], $allowed, true), 422, $current === 'expired' && $data['status'] === 'accepted'
            ? 'This offer has expired. Extend the expiry date and re-send it first.'
            : "A {$current} offer cannot be marked {$data['status']}.");
        abort_if($data['status'] === 'sent' && $offer->expiry_date?->isPast(), 422, 'Set an expiry date in the future before sending the offer.');

        $offer->forceFill(['status' => $data['status']] + ($data['status'] === 'sent' ? ['sent_at' => now()] : ['responded_at' => now()]))->save();
        if ($data['status'] === 'accepted' && $offer->applicant) {
            $offer->applicant->update(['stage' => 'hired', 'hired_at' => $offer->applicant->hired_at ?? now()]);
        }
        activity('recruitment')->performedOn($offer)->causedBy($request->user())->withProperties(['status' => $data['status']])->log("Offer {$data['status']}");

        return response()->json(['data' => $offer->fresh(), 'message' => 'Offer marked '.$data['status'].'.']);
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
