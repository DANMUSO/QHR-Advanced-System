<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Documents\Models\CompanyDocument;
use App\Modules\Documents\Models\CompanyDocumentAcknowledgement;
use App\Modules\Documents\Models\CompanyDocumentVersion;
use App\Modules\Documents\Notifications\AcknowledgementReminder;
use App\Modules\Documents\Notifications\CompanyDocumentPublished;
use App\Modules\Documents\Support\DocumentAccess;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Memos, newsletters and policies: staff library, HR management and acknowledgement tracking. */
class CompanyDocumentController extends Controller
{
    /** Library. Staff see documents addressed to them; HR sees everything (with acknowledgement stats). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manages = DocumentAccess::manages($user) && $request->query('view') !== 'staff';
        $employee = $user->employee;
        abort_unless($manages || $employee, 404, 'Your account is not linked to an employee record.');

        $query = CompanyDocument::query()
            ->with(['referredBy:id,name', 'creator:id,name'])
            ->when(! $manages, fn ($q) => $q->visibleTo($employee))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->when($request->integer('department_id'), fn ($q, $d) => $q->where('referred_by_department_id', $d))
            ->when($request->boolean('requires_acknowledgement'), fn ($q) => $q->where('requires_acknowledgement', true));

        if ($employee) {
            $query->withExists(['acknowledgements as acknowledged' => fn ($q) => $q->where('employee_id', $employee->id)]);
            if ($request->query('status') === 'pending') {
                $query->where('requires_acknowledgement', true)
                    ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('employee_id', $employee->id));
            }
        }
        $query->withCount('acknowledgements')->orderByDesc('effective_date')->orderByDesc('id');

        $page = $query->paginate(min($request->integer('per_page', 20), 100));
        $page->through(fn (CompanyDocument $d) => $this->present($d, $manages));

        $counts = CompanyDocument::query()->when(! $manages, fn ($q) => $q->visibleTo($employee))
            ->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category');

        return response()->json([...$page->toArray(), 'category_counts' => $counts,
            'pending_acknowledgements' => $employee ? $this->pendingCount($employee) : 0]);
    }

    public function show(Request $request, CompanyDocument $companyDocument): JsonResponse
    {
        $user = $request->user();
        abort_unless(DocumentAccess::canViewCompanyDocument($user, $companyDocument), 403);
        $manages = DocumentAccess::manages($user);
        $companyDocument->load(['referredBy:id,name', 'creator:id,name', 'versions.uploader:id,name'])->loadCount('acknowledgements');
        if ($user->employee) {
            $ack = $companyDocument->acknowledgements()->where('employee_id', $user->employee->id)->first();
            $companyDocument->setAttribute('acknowledged', (bool) $ack);
            $companyDocument->setAttribute('acknowledged_at', $ack?->acknowledged_at);
        }

        return response()->json([
            ...$this->present($companyDocument, $manages),
            'acknowledged_at' => $companyDocument->acknowledged_at,
            'versions' => $companyDocument->versions->map(fn ($v) => [
                'id' => $v->id, 'version' => $v->version, 'file_name' => $v->file_name, 'size_human' => DocumentAccess::humanSize((int) $v->file_size),
                'uploaded_by' => $v->uploader?->name, 'uploaded_at' => $v->uploaded_at,
            ]),
            'history' => $manages ? \Spatie\Activitylog\Models\Activity::query()->where('subject_type', CompanyDocument::class)
                ->where('subject_id', $companyDocument->id)->with('causer:id,name')->latest()->limit(30)->get()
                ->map(fn ($a) => ['id' => $a->id, 'event' => $a->event, 'description' => $a->description, 'by' => $a->causer?->name, 'at' => $a->created_at]) : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = Tenancy::companyIdForWrite($request->integer('company_id') ?: null);
        abort_unless($companyId, 422, 'Choose a company first.');
        $data = $this->validated($request, $companyId, true);

        $doc = new CompanyDocument([...$data, ...DocumentAccess::store($request->file('file'), "documents/company/{$companyId}")]);
        $doc->company_id = $companyId;
        $doc->created_by = $request->user()->id;
        $doc->version = 1;
        $doc->save();

        if ($request->boolean('notify', true)) {
            $this->notifyAudience($doc);
        }

        return response()->json($this->present($doc->load(['referredBy:id,name', 'creator:id,name']), true), 201);
    }

    /** Update metadata and optionally replace the file (multipart POST). */
    public function update(Request $request, CompanyDocument $companyDocument): JsonResponse
    {
        $data = $this->validated($request, $companyDocument->company_id, false);

        DB::transaction(function () use ($request, $companyDocument, $data) {
            if ($request->hasFile('file')) {
                CompanyDocumentVersion::create([
                    'company_document_id' => $companyDocument->id,
                    'version' => $companyDocument->version,
                    'file_path' => $companyDocument->file_path,
                    'file_name' => $companyDocument->file_name,
                    'mime_type' => $companyDocument->mime_type,
                    'file_size' => $companyDocument->file_size,
                    'uploaded_by' => $companyDocument->created_by,
                    'uploaded_at' => $companyDocument->updated_at,
                ]);
                $data = [...$data, ...DocumentAccess::store($request->file('file'), "documents/company/{$companyDocument->company_id}"), 'version' => $companyDocument->version + 1];
                if ($request->boolean('reset_acknowledgements')) {
                    $companyDocument->acknowledgements()->delete();
                }
            }
            $companyDocument->update($data);
        });

        return response()->json($this->present($companyDocument->fresh(['referredBy:id,name', 'creator:id,name'])->loadCount('acknowledgements'), true));
    }

    public function destroy(CompanyDocument $companyDocument): JsonResponse
    {
        $companyDocument->delete();

        return response()->json(['message' => 'Document deleted.']);
    }

    public function download(Request $request, CompanyDocument $companyDocument): StreamedResponse
    {
        abort_unless(DocumentAccess::canViewCompanyDocument($request->user(), $companyDocument), 403);
        $file = $companyDocument;
        if ($request->filled('version')) {
            abort_unless(DocumentAccess::manages($request->user()), 403);
            $file = $companyDocument->versions()->whereKey($request->integer('version'))->firstOrFail();
        }

        return DocumentAccess::respond($file->file_path, $file->file_name, $file->mime_type, $request->boolean('inline'));
    }

    /** "I have read and understood this document." */
    public function acknowledge(Request $request, CompanyDocument $companyDocument): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        abort_unless($employee && $companyDocument->isAddressedTo($employee), 403);
        abort_unless($companyDocument->requires_acknowledgement, 422, 'This document does not require acknowledgement.');

        $ack = CompanyDocumentAcknowledgement::firstOrCreate(
            ['company_document_id' => $companyDocument->id, 'employee_id' => $employee->id],
            ['user_id' => $user->id, 'version' => $companyDocument->version, 'ip_address' => $request->ip(), 'acknowledged_at' => now()],
        );
        if ($ack->wasRecentlyCreated) {
            activity('documents')->performedOn($companyDocument)->causedBy($user)->event('acknowledged')
                ->withProperties(['employee_id' => $employee->id, 'version' => $companyDocument->version])
                ->log("{$employee->full_name} acknowledged \"{$companyDocument->title}\"");
        }

        return response()->json(['message' => 'Thank you — your acknowledgement has been recorded.', 'acknowledged_at' => $ack->acknowledged_at]);
    }

    /** HR: who has and hasn't acknowledged. */
    public function acknowledgements(Request $request, CompanyDocument $companyDocument): JsonResponse
    {
        $acks = $companyDocument->acknowledgements()->get()->keyBy('employee_id');
        $employees = $companyDocument->audienceQuery()->with(['department:id,name', 'branch:id,name'])
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_number', 'department_id', 'branch_id', 'photo_path', 'user_id']);

        $rows = $employees->map(fn (Employee $e) => [
            'employee_id' => $e->id, 'full_name' => $e->full_name, 'employee_number' => $e->employee_number,
            'department' => $e->department?->name, 'branch' => $e->branch?->name, 'photo_url' => $e->photo_url,
            'has_account' => (bool) $e->user_id,
            'acknowledged_at' => $acks[$e->id]->acknowledged_at ?? null,
            'version' => $acks[$e->id]->version ?? null,
        ]);
        $done = $rows->whereNotNull('acknowledged_at')->count();

        return response()->json([
            'document' => $this->present($companyDocument->loadCount('acknowledgements'), true),
            'summary' => [
                'audience' => $rows->count(), 'acknowledged' => $done, 'pending' => $rows->count() - $done,
                'percent' => $rows->count() ? (int) round($done / $rows->count() * 100) : 0,
                'last_reminded_at' => $companyDocument->last_reminded_at,
            ],
            'data' => $rows->values(),
        ]);
    }

    /** HR: remind everyone who hasn't acknowledged yet. */
    public function remind(Request $request, CompanyDocument $companyDocument): JsonResponse
    {
        abort_unless($companyDocument->requires_acknowledgement, 422, 'This document does not require acknowledgement.');
        if ($companyDocument->last_reminded_at?->gt(now()->subMinutes(10))) {
            abort(429, 'A reminder was sent a few minutes ago. Please wait before sending another.');
        }
        $ackIds = $companyDocument->acknowledgements()->pluck('employee_id');
        $users = $companyDocument->audienceQuery()->whereNotIn('id', $ackIds)->whereNotNull('user_id')
            ->with('user')->get()->pluck('user')->filter(fn ($u) => $u && $u->is_active);

        Notification::send($users, new AcknowledgementReminder($companyDocument));
        $companyDocument->forceFill(['last_reminded_at' => now()])->saveQuietly();

        return response()->json(['message' => "Reminder sent to {$users->count()} staff member(s).", 'sent' => $users->count()]);
    }

    private function notifyAudience(CompanyDocument $doc): void
    {
        $users = $doc->audienceQuery()->whereNotNull('user_id')->with('user')->get()->pluck('user')
            ->filter(fn ($u) => $u && $u->is_active && $u->id !== auth()->id());
        Notification::send($users, new CompanyDocumentPublished($doc));
    }

    private function pendingCount(Employee $employee): int
    {
        return CompanyDocument::query()->visibleTo($employee)->where('requires_acknowledgement', true)
            ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('employee_id', $employee->id))->count();
    }

    private function validated(Request $request, int $companyId, bool $creating): array
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(CompanyDocument::CATEGORIES)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'referred_by_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'effective_date' => ['nullable', 'date'],
            'audience' => ['required', Rule::in(['all', 'department', 'branch'])],
            'audience_ids' => ['nullable', 'array', 'required_unless:audience,all'],
            'audience_ids.*' => ['integer', Rule::exists($request->input('audience') === 'branch' ? 'branches' : 'departments', 'id')->where('company_id', $companyId)],
            'requires_acknowledgement' => ['boolean'],
            'file' => [$creating ? 'required' : 'nullable', 'file', 'mimes:'.DocumentAccess::MIMES, 'max:'.DocumentAccess::HR_MAX_KB],
        ], ['file.mimes' => 'Upload a PDF, Word (DOC/DOCX), ODT, JPG or PNG file.']);

        $data['audience_ids'] = $data['audience'] === 'all' ? null : array_values(array_unique(array_map('intval', $data['audience_ids'] ?? [])));
        $data['requires_acknowledgement'] = $request->boolean('requires_acknowledgement');

        return collect($data)->except('file')->all();
    }

    private function present(CompanyDocument $d, bool $manages): array
    {
        $out = [
            'id' => $d->id,
            'company_id' => $d->company_id,
            'category' => $d->category,
            'title' => $d->title,
            'description' => $d->description,
            'referred_by_department_id' => $d->referred_by_department_id,
            'referred_by' => $d->referredBy?->name,
            'effective_date' => $d->effective_date?->toDateString(),
            'audience' => $d->audience,
            'audience_ids' => $d->audience_ids ?? [],
            'requires_acknowledgement' => $d->requires_acknowledgement,
            'acknowledged' => (bool) ($d->acknowledged ?? false),
            'file_name' => $d->file_name,
            'mime_type' => $d->mime_type,
            'size_human' => DocumentAccess::humanSize((int) $d->file_size),
            'previewable' => in_array($d->mime_type, DocumentAccess::PREVIEWABLE, true),
            'version' => $d->version,
            'created_by' => $d->creator?->name,
            'created_at' => $d->created_at,
            'updated_at' => $d->updated_at,
        ];
        if ($manages && $d->requires_acknowledgement) {
            $audience = $d->audienceQuery()->count();
            $done = $d->acknowledgements()->whereIn('employee_id', $d->audienceQuery()->select('id'))->count();
            $out['ack_stats'] = ['audience' => $audience, 'acknowledged' => $done, 'percent' => $audience ? (int) round($done / $audience * 100) : 0];
        }

        return $out;
    }
}
