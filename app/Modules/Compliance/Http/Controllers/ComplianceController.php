<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Compliance\Models\BackgroundCheck;
use App\Modules\Compliance\Models\ComplianceRecord;
use App\Modules\Compliance\Models\MedicalCover;
use Illuminate\Support\Facades\DB;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\DisciplinaryCase;
use App\Modules\Compliance\Models\Grievance;
use App\Modules\Compliance\Models\Hazard;
use App\Modules\Compliance\Models\Incident;
use App\Modules\Compliance\Models\WarningLetter;
use App\Modules\Compliance\Notifications\ComplianceNotification;
use App\Modules\Compliance\Services\ComplianceService;
use App\Modules\Engagement\Support\AuthorHash;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Compliance registers: disciplinary cases, grievances, warning letters (ComplianceCases.vue),
 * health & safety hazards and incidents (HealthSafetyHub.vue) and data-subject requests (DataProtection.vue).
 * Every list is a Laravel paginator ({data, current_page, last_page, total, from, to}); writes are audited
 * through the models' LogsActivity trait.
 */
class ComplianceController extends Controller
{
    public const TYPES = ['disciplinary', 'grievances', 'warnings', 'health-safety', 'incidents', 'data-protection', 'crb', 'medical'];

    private const MODELS = [
        'disciplinary' => DisciplinaryCase::class,
        'grievances' => Grievance::class,
        'warnings' => WarningLetter::class,
        'health-safety' => Hazard::class,
        'incidents' => Incident::class,
        'data-protection' => DataRequest::class,
        'crb' => BackgroundCheck::class,
        'medical' => MedicalCover::class,
    ];

    private const RELATIONS = [
        'disciplinary' => ['employee:id,first_name,last_name,employee_number,photo_path'],
        'grievances' => ['employee:id,first_name,last_name,employee_number,photo_path', 'assignedTo:id,first_name,last_name'],
        'warnings' => ['employee:id,first_name,last_name,employee_number,photo_path', 'issuedBy:id,name'],
        'health-safety' => ['reportedBy:id,name'],
        'incidents' => ['reportedBy:id,name', 'employee:id,first_name,last_name'],
        'data-protection' => ['employee:id,first_name,last_name'],
        'crb' => ['employee:id,first_name,last_name,employee_number,photo_path'],
        'medical' => ['employee:id,first_name,last_name,employee_number,photo_path'],
    ];

    public function __construct(private ComplianceService $service) {}

    public function index(Request $request, string $type): JsonResponse
    {
        $this->authorizeType($request, $type);
        $model = self::MODELS[$type];
        $query = $model::query()->with(self::RELATIONS[$type])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('employee_id'), fn (Builder $q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('search'), fn (Builder $q) => $this->search($q, $type, (string) $request->string('search')))
            ->latest('id');

        return response()->json(
            $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->through(fn ($r) => $this->present($type, $r))
        );
    }

    public function show(Request $request, string $type, string $id): JsonResponse
    {
        $this->authorizeType($request, $type);
        $record = self::MODELS[$type]::with(self::RELATIONS[$type])->findOrFail($id);

        return response()->json(['data' => $this->present($type, $record)]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $this->authorizeType($request, $type);
        $data = $this->validated($request, $type);
        $employee = isset($data['employee_id']) ? $this->resolveEmployee($data['employee_id']) : null;
        if ($employee) {
            $data['employee_id'] = $employee->id;
        }

        $generateLetter = $type === 'warnings' && $request->boolean('generate_letter', true);
        unset($data['generate_letter']);
        $record = $this->fillNew($type, $data, $request);
        $record->company_id = $employee?->company_id ?? $this->writeCompanyId($request);
        $this->saveWithNumber($type, $record);
        if ($generateLetter) {
            $this->service->generateLetter($record, $request->user());
        }
        $this->notifyCreated($type, $record);

        $payload = $this->present($type, $record->fresh(self::RELATIONS[$type]));

        return response()->json(['message' => 'Saved.', 'data' => $payload, 'pdf_url' => $payload['pdf_url'] ?? null], 201);
    }

    /** Generate (or fetch) the Employees "warning" letter for a warning and link it to the record. */
    public function warningLetter(Request $request, string $id): JsonResponse
    {
        $this->authorizeType($request, 'warnings');
        $warning = WarningLetter::findOrFail($id);
        $letter = $this->service->generateLetter($warning, $request->user());

        return response()->json([
            'message' => 'Warning letter generated.', 'letter_id' => $letter->id, 'pdf_url' => ComplianceService::letterUrl($letter->id),
            'data' => $this->present('warnings', $warning->fresh(self::RELATIONS['warnings'])),
        ]);
    }

    public function update(Request $request, string $type, string $id): JsonResponse
    {
        $this->authorizeType($request, $type);
        $record = self::MODELS[$type]::findOrFail($id);
        $data = $this->validated($request, $type, $record);
        if (isset($data['employee_id'])) {
            $employee = $this->resolveEmployee($data['employee_id']);
            abort_unless($employee->company_id === $record->company_id, 422, 'Employee belongs to another company.');
            $data['employee_id'] = $employee->id;
        }
        unset($data['expiry_months']);
        if ($type === 'data-protection' && ($data['status'] ?? null) === 'completed' && ! $record->completed_at) {
            $data['completed_at'] = now();
        }
        $before = $record->only(['status', 'hearing_date', 'outcome', 'assigned_to_employee_id']);
        $record->update($data);
        $this->notifyUpdated($type, $record, $before);
        if ($type === 'disciplinary' && $record->wasChanged('outcome')) {
            $this->service->warningFromCase($record, $request->user());
        }

        return response()->json(['message' => 'Updated.', 'data' => $this->present($type, $record->fresh(self::RELATIONS[$type]))]);
    }

    public function destroy(Request $request, string $type, string $id): JsonResponse
    {
        $this->authorizeType($request, $type);
        self::MODELS[$type]::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ---- validation --------------------------------------------------------------------------

    private function validated(Request $request, string $type, ?ComplianceRecord $record = null): array
    {
        $r = $record ? 'sometimes' : 'required';

        $rules = match ($type) {
            'disciplinary' => [
                'employee_id' => [$r],
                'offence' => [$r, 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'reported_date' => ['nullable', 'date'],
                'hearing_date' => ['nullable', 'date'],
                'outcome' => ['nullable', Rule::in(['pending', 'warning', 'final_warning', 'suspension', 'dismissal', 'exonerated'])],
                'outcome_notes' => ['nullable', 'string', 'max:5000'],
                'status' => ['sometimes', Rule::in(['open', 'under_investigation', 'hearing_scheduled', 'closed', 'appealed'])],
            ],
            'grievances' => [
                'employee_id' => [$r],
                'category' => ['sometimes', Rule::in(Grievance::CATEGORIES)],
                'subject' => ['nullable', 'string', 'max:200'],
                'description' => [$r, 'string', 'max:5000'],
                'filed_date' => ['nullable', 'date'],
                'assigned_to_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
                'resolution' => ['nullable', 'string', 'max:5000'],
                'status' => ['sometimes', Rule::in(['open', 'investigating', 'mediation', 'resolved', 'escalated'])],
            ],
            'warnings' => [
                'employee_id' => [$r],
                'level' => ['sometimes', Rule::in(['verbal', 'written', 'final'])],
                'reason' => [$r, 'string', 'max:5000'],
                'issued_date' => ['nullable', 'date'],
                'expiry_months' => ['nullable', 'integer', 'min:1', 'max:36'],
                'expiry_date' => ['nullable', 'date'],
                'disciplinary_case_id' => ['nullable', 'integer', 'exists:comp_disciplinary_cases,id'],
                'generate_letter' => ['sometimes', 'boolean'],
            ],
            'health-safety' => [
                'title' => [$r, 'string', 'max:255'],
                'category' => ['sometimes', Rule::in(['hazard', 'near_miss', 'accident', 'fire_safety', 'ergonomics'])],
                'risk_level' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
                'description' => ['nullable', 'string', 'max:5000'],
                'reported_date' => ['nullable', 'date'],
                'action_taken' => ['nullable', 'string', 'max:5000'],
                'status' => ['sometimes', Rule::in(['open', 'in_progress', 'closed'])],
            ],
            'incidents' => [
                'title' => [$r, 'string', 'max:255'],
                'type' => ['sometimes', Rule::in(['accident', 'near_miss', 'property_damage', 'security'])],
                'severity' => ['sometimes', Rule::in(['minor', 'moderate', 'serious', 'critical'])],
                'location' => ['nullable', 'string', 'max:255'],
                'incident_date' => ['nullable', 'date'],
                'description' => ['nullable', 'string', 'max:5000'],
                'employee_id' => ['nullable'],
                'corrective_action' => ['nullable', 'string', 'max:5000'],
                'status' => ['sometimes', Rule::in(['open', 'investigating', 'closed'])],
            ],
            'data-protection' => [
                'requester_name' => [$r, 'string', 'max:255'],
                'requester_email' => ['nullable', 'email', 'max:255'],
                'employee_id' => ['nullable'],
                'type' => ['sometimes', Rule::in(['access', 'erasure', 'rectification', 'portability', 'objection'])],
                'description' => ['nullable', 'string', 'max:5000'],
                'submitted_date' => ['nullable', 'date'],
                'due_date' => ['nullable', 'date'],
                'response_notes' => ['nullable', 'string', 'max:5000'],
                'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'rejected'])],
            ],
            'crb' => [
                'employee_id' => [$r],
                'type' => ['sometimes', Rule::in(['good_conduct', 'crb_check', 'police_clearance'])],
                'reference_number' => ['nullable', 'string', 'max:80'],
                'issue_date' => ['nullable', 'date'],
                'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'status' => ['sometimes', Rule::in(['pending', 'clear', 'flagged', 'expired'])],
            ],
            'medical' => [
                'employee_id' => [$r],
                'scheme_name' => [$r, 'string', 'max:150'],
                'member_number' => ['nullable', 'string', 'max:80'],
                'cover_limit' => ['nullable', 'numeric', 'min:0'],
                'issue_date' => ['nullable', 'date'],
                'expiry_date' => ['nullable', 'date'],
                'status' => ['sometimes', Rule::in(['active', 'pending', 'expired'])],
            ],
        };

        return array_filter($request->validate($rules), fn ($v, $k) => ! ($k === 'employee_id' && ($v === '' || $v === null)), ARRAY_FILTER_USE_BOTH);
    }

    /** Builds a new record with sensible defaults for blank dates/status. */
    private function fillNew(string $type, array $data, Request $request): ComplianceRecord
    {
        $data = array_filter($data, fn ($v) => $v !== null); // blank form fields fall back to the defaults below
        $today = today();
        $data = match ($type) {
            'disciplinary' => $data + ['reported_date' => $today, 'status' => 'open', 'reported_by_user_id' => $request->user()->id],
            'grievances' => $data + ['filed_date' => $today, 'category' => 'workplace', 'status' => 'open'],
            'warnings' => (function () use ($data, $today, $request) {
                $issued = Carbon::parse($data['issued_date'] ?? $today);
                $data['issued_date'] = $issued;
                $data['expiry_date'] ??= $issued->copy()->addMonths((int) ($data['expiry_months'] ?? 6));
                unset($data['expiry_months']);

                return $data + ['level' => 'written', 'issued_by_user_id' => $request->user()->id];
            })(),
            'health-safety' => $data + ['reported_date' => $today, 'status' => 'open', 'reported_by_user_id' => $request->user()->id],
            'incidents' => $data + ['incident_date' => now(), 'status' => 'open', 'reported_by_user_id' => $request->user()->id],
            'data-protection' => (function () use ($data, $today) {
                $submitted = Carbon::parse($data['submitted_date'] ?? $today);
                $data['submitted_date'] = $submitted;
                // Kenya Data Protection (General) Regulations: access within 7 days, rectification/erasure within 14, others 30.
                $days = ['access' => 7, 'rectification' => 14, 'erasure' => 14][$data['type'] ?? 'access'] ?? 30;
                $data['due_date'] ??= $submitted->copy()->addDays($days);

                return $data + ['type' => 'access', 'status' => 'pending'];
            })(),
            'crb' => $data + ['type' => 'good_conduct', 'status' => empty($data['issue_date']) ? 'pending' : 'clear'],
            'medical' => $data + ['status' => 'active'],
        };

        $class = self::MODELS[$type];

        return new $class($data);
    }

    /** Assigns the human reference number (DC-2026-0001 …) and saves, retrying on a rare collision. */
    private function saveWithNumber(string $type, ComplianceRecord $record): void
    {
        $column = ['disciplinary' => 'case_number', 'grievances' => 'reference', 'incidents' => 'incident_number', 'data-protection' => 'reference'][$type] ?? null;
        if (! $column) {
            $record->save();

            return;
        }
        $prefix = ['disciplinary' => 'DC', 'grievances' => 'GRV', 'incidents' => 'INC', 'data-protection' => 'DSR'][$type];
        ComplianceService::saveWithNumber($record, $column, $prefix);
    }

    // ---- notifications -----------------------------------------------------------------------

    private function notifyCreated(string $type, ComplianceRecord $r): void
    {
        $employee = $r->employee_id ? Employee::find($r->employee_id) : null;
        match ($type) {
            'disciplinary' => $this->service->notifyEmployee($employee, 'Disciplinary case opened',
                "A disciplinary case ({$r->case_number}) has been opened regarding: {$r->offence}."
                .($r->hearing_date ? ' Hearing date: '.$r->hearing_date->format('d M Y').'.' : ' HR will contact you with the next steps.')),
            'warnings' => $this->service->notifyEmployee($employee, 'Warning letter issued',
                'A '.($r->level ?? 'written').' warning has been issued to you on '.$r->issued_date?->format('d M Y').'.'
                .($r->letter_id ? ' You can download the letter from your documents.' : '')),
            'grievances' => $this->service->notifyEmployee($employee, 'Grievance recorded',
                "Your grievance {$r->reference} has been recorded by HR."),
            default => null,
        };
        if ($type === 'grievances' && $r->assigned_to_employee_id) {
            $this->service->notifyEmployee(Employee::find($r->assigned_to_employee_id), 'Grievance assigned to you',
                "Grievance {$r->reference} has been assigned to you for investigation.", '/compliance?tab=grievances');
        }
    }

    private function notifyUpdated(string $type, ComplianceRecord $r, array $before): void
    {
        if ($type === 'disciplinary') {
            $employee = Employee::find($r->employee_id);
            if ($r->hearing_date && $r->hearing_date->toDateString() !== ($before['hearing_date'] ?? null)?->toDateString()) {
                $this->service->notifyEmployee($employee, 'Disciplinary hearing scheduled',
                    "The hearing for case {$r->case_number} is scheduled for {$r->hearing_date->format('d M Y')}.");
            }
            if ($r->status !== $before['status'] && in_array($r->status, ['closed', 'appealed'], true)) {
                $this->service->notifyEmployee($employee, "Disciplinary case {$r->status}",
                    "Case {$r->case_number} is now {$r->status}".($r->outcome && $r->outcome !== 'pending' ? ' (outcome: '.str_replace('_', ' ', $r->outcome).').' : '.'));
            }
        }
        if ($type === 'grievances') {
            if ($r->status !== $before['status']) {
                $message = "Your grievance {$r->reference} is now ".str_replace('_', ' ', $r->status).'.'.($r->status === 'resolved' && $r->resolution ? " Resolution: {$r->resolution}" : '');
                $this->notifyGrievanceAuthor($r, 'Grievance update', $message);
            }
            if ($r->assigned_to_employee_id && $r->assigned_to_employee_id !== ($before['assigned_to_employee_id'] ?? null)) {
                $this->service->notifyEmployee(Employee::find($r->assigned_to_employee_id), 'Grievance assigned to you',
                    "Grievance {$r->reference} has been assigned to you for investigation.", '/compliance?tab=grievances');
            }
        }
    }

    /** Anonymous grievances reach their author through the author hash; HR never learns who it is. */
    private function notifyGrievanceAuthor(ComplianceRecord $r, string $title, string $message): void
    {
        if ($r->employee_id) {
            $this->service->notifyEmployee(Employee::find($r->employee_id), $title, $message);

            return;
        }
        $user = AuthorHash::findUser($r->author_hash, $r->company_id);
        try {
            $user?->notify(new ComplianceNotification($title, $message, '/portal'));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function authorizeType(Request $request, string $type): void
    {
        $permission = $type === "data-protection" ? "settings.manage" : "employees.update";
        abort_unless($request->user()->can($permission), 403, "This action is unauthorized.");
    }

    /** The forms accept a numeric id or an employee number. Scoped to the viewer's company. */
    private function resolveEmployee(mixed $value): Employee
    {
        $value = trim((string) $value);
        $employee = ctype_digit($value) ? Employee::find((int) $value) : null;
        $employee ??= Employee::where('employee_number', $value)->first();
        abort_unless($employee, 422, 'Employee not found. Pick an employee from the list.');

        return $employee;
    }

    private function writeCompanyId(Request $request): int
    {
        $id = Tenancy::companyIdForWrite($request->integer('company_id') ?: null) ?? $request->user()->company_id;
        abort_unless($id, 422, 'Select a company first.');

        return (int) $id;
    }

    private function search(Builder $q, string $type, string $term): void
    {
        $like = '%'.$term.'%';
        $employee = fn (Builder $e) => $e->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('employee_number', 'like', $like);
        match ($type) {
            'disciplinary' => $q->where(fn ($w) => $w->where('offence', 'like', $like)->orWhere('case_number', 'like', $like)->orWhereHas('employee', $employee)),
            'grievances' => $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('subject', 'like', $like)->orWhereHas('employee', $employee)),
            'warnings' => $q->where(fn ($w) => $w->where('reason', 'like', $like)->orWhereHas('employee', $employee)),
            'health-safety', 'incidents' => $q->where('title', 'like', $like),
            'data-protection' => $q->where(fn ($w) => $w->where('requester_name', 'like', $like)->orWhere('reference', 'like', $like)),
            'crb', 'medical' => $q->whereHas('employee', $employee),
        };
    }

    private function present(string $type, ComplianceRecord $r): array
    {
        $employee = $r->relationLoaded('employee') ? $r->employee : null;
        $base = ['id' => $r->id, 'company_id' => $r->company_id, 'created_at' => $r->created_at?->toIso8601String()];
        $who = $employee ? ['employee_id' => $employee->id, 'employee_name' => $employee->full_name, 'employee_number' => $employee->employee_number] : ['employee_id' => $r->employee_id ?? null];

        return $base + $who + match ($type) {
            'disciplinary' => [
                'case_number' => $r->case_number, 'offence' => $r->offence, 'description' => $r->description,
                'reported_date' => $r->reported_date?->toDateString(), 'hearing_date' => $r->hearing_date?->toDateString(),
                'outcome' => $r->outcome, 'outcome_notes' => $r->outcome_notes, 'status' => $r->status, 'pip_id' => $r->pip_id,
            ],
            'grievances' => [
                'reference' => $r->reference, 'category' => $r->category, 'subject' => $r->subject, 'description' => $r->description,
                'anonymous' => (bool) $r->anonymous, 'employee_name' => $r->anonymous ? 'Anonymous' : ($employee?->full_name ?? null),
                'filed_date' => $r->filed_date?->toDateString(), 'assigned_to_employee_id' => $r->assigned_to_employee_id,
                'assigned_to' => $r->assignedTo?->full_name, 'resolution' => $r->resolution, 'status' => $r->status,
            ],
            'warnings' => [
                'level' => $r->level, 'reason' => $r->reason, 'issued_date' => $r->issued_date?->toDateString(),
                'expiry_date' => $r->expiry_date?->toDateString(), 'issued_by' => $r->issuedBy?->name,
                'is_active' => ! $r->expiry_date || $r->expiry_date->isFuture(), 'disciplinary_case_id' => $r->disciplinary_case_id,
                'letter_id' => $r->letter_id, 'pdf_url' => ComplianceService::letterUrl($r->letter_id),
            ],
            'health-safety' => [
                'title' => $r->title, 'category' => $r->category, 'risk_level' => $r->risk_level, 'description' => $r->description,
                'reported_date' => $r->reported_date?->toDateString(), 'reported_by' => $r->reportedBy?->name,
                'action_taken' => $r->action_taken, 'status' => $r->status,
            ],
            'incidents' => [
                'incident_number' => $r->incident_number, 'title' => $r->title, 'type' => $r->type, 'severity' => $r->severity,
                'location' => $r->location, 'incident_date' => $r->incident_date?->format('Y-m-d H:i'), 'description' => $r->description,
                'reported_by' => $r->reportedBy?->name, 'corrective_action' => $r->corrective_action, 'status' => $r->status,
            ],
            'data-protection' => [
                'reference' => $r->reference, 'requester_name' => $r->requester_name, 'requester_email' => $r->requester_email,
                'type' => $r->type, 'description' => $r->description, 'submitted_date' => $r->submitted_date?->toDateString(),
                'due_date' => $r->due_date?->toDateString(), 'status' => $r->status, 'response_notes' => $r->response_notes,
                'is_overdue' => in_array($r->status, ['pending', 'in_progress'], true) && $r->due_date?->isPast(),
            ],
            'crb' => [
                'type' => $r->type, 'reference_number' => $r->reference_number, 'notes' => $r->notes,
                'issue_date' => $r->issue_date?->toDateString(), 'expiry_date' => $r->expiry_date?->toDateString(),
                'status' => $r->expiry_date?->isPast() ? 'expired' : $r->status,
            ],
            'medical' => [
                'scheme_name' => $r->scheme_name, 'member_number' => $r->member_number,
                'cover_limit' => $r->cover_limit !== null ? (float) $r->cover_limit : null,
                'dependants' => DB::table('employee_dependants')->where('employee_id', $r->employee_id)->count(),
                'issue_date' => $r->issue_date?->toDateString(), 'expiry_date' => $r->expiry_date?->toDateString(),
                'status' => $r->expiry_date?->isPast() ? 'expired' : $r->status,
            ],
        };
    }
}
