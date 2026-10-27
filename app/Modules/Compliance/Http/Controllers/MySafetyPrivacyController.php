<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\Hazard;
use App\Modules\Compliance\Models\Incident;
use App\Modules\Compliance\Services\ComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employee self-service for the health & safety and data-protection registers (/api/my/health-safety,
 * /api/my/data-requests), following the /my/grievances pattern: the caller only sees what they reported or asked for.
 */
class MySafetyPrivacyController extends Controller
{
    /** Data-subject request types an employee can raise, with the statutory response window in days (as HR's register). */
    public const DSR_TYPES = ['access' => 7, 'rectification' => 14, 'erasure' => 14];

    public function __construct(private ComplianceService $service) {}

    /** GET /my/health-safety: hazards and incidents I reported. */
    public function safety(Request $request): JsonResponse
    {
        $this->me($request);
        $userId = $request->user()->id;
        $hazards = Hazard::where('reported_by_user_id', $userId)->latest('id')->get()->map(fn (Hazard $h) => [
            'id' => $h->id, 'kind' => 'hazard', 'reference' => null, 'title' => $h->title, 'category' => $h->category,
            'level' => $h->risk_level, 'description' => $h->description, 'date' => $h->reported_date?->toDateString(),
            'location' => null, 'status' => $h->status, 'outcome' => $h->action_taken,
        ]);
        $incidents = Incident::where('reported_by_user_id', $userId)->latest('id')->get()->map(fn (Incident $i) => [
            'id' => $i->id, 'kind' => 'incident', 'reference' => $i->incident_number, 'title' => $i->title, 'category' => $i->type,
            'level' => $i->severity, 'description' => $i->description, 'date' => $i->incident_date?->format('Y-m-d H:i'),
            'location' => $i->location, 'status' => $i->status, 'outcome' => $i->corrective_action,
        ]);

        return response()->json(['data' => $hazards->concat($incidents)->sortByDesc('date')->values()]);
    }

    /** POST /my/health-safety {kind: hazard|incident, title, category, level, date, location?, description, affected_me?} */
    public function reportSafety(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $kind = $request->input('kind');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['hazard', 'incident'])],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', $kind === 'incident'
                ? Rule::in(['accident', 'near_miss', 'property_damage', 'security'])
                : Rule::in(['hazard', 'near_miss', 'accident', 'fire_safety', 'ergonomics'])],
            'level' => ['nullable', $kind === 'incident' ? Rule::in(['minor', 'moderate', 'serious', 'critical']) : Rule::in(['low', 'medium', 'high', 'critical'])],
            // Something that already happened (or was spotted): never a future date.
            'date' => ['nullable', 'date', 'before_or_equal:'.($kind === 'incident' ? now()->toDateTimeString() : today()->toDateString())],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'affected_me' => ['sometimes', 'boolean'],
        ], ['date.before_or_equal' => 'The date cannot be in the future.']);

        if ($data['kind'] === 'hazard') {
            $record = new Hazard([
                'title' => $data['title'], 'category' => $data['category'] ?? 'hazard', 'risk_level' => $data['level'] ?? 'medium',
                'description' => $data['description'], 'reported_date' => $data['date'] ?? today(), 'status' => 'open',
                'reported_by_user_id' => $request->user()->id,
            ]);
            $record->company_id = $me->company_id;
            $record->save();
            $label = 'hazard';
        } else {
            $record = new Incident([
                'title' => $data['title'], 'type' => $data['category'] ?? 'accident', 'severity' => $data['level'] ?? 'minor',
                'location' => $data['location'] ?? null, 'incident_date' => $data['date'] ?? now(), 'description' => $data['description'],
                'employee_id' => ! empty($data['affected_me']) ? $me->id : null, 'status' => 'open', 'reported_by_user_id' => $request->user()->id,
            ]);
            $record->company_id = $me->company_id;
            ComplianceService::saveWithNumber($record, 'incident_number', 'INC');
            $label = "incident {$record->incident_number}";
        }

        $this->service->notifyHr((int) $me->company_id, 'Health & safety report',
            "{$me->full_name} reported a {$label}: {$record->title}", '/compliance/hs', [$request->user()->id]);

        return response()->json(['message' => 'Thank you. Your report has been sent to HR.', 'data' => ['id' => $record->id, 'kind' => $data['kind']]], 201);
    }

    /** GET /my/data-requests: my data-subject requests. */
    public function dataRequests(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $rows = DataRequest::where('employee_id', $me->id)->latest('id')->get()->map(fn (DataRequest $r) => [
            'id' => $r->id, 'reference' => $r->reference, 'type' => $r->type, 'description' => $r->description,
            'submitted_date' => $r->submitted_date?->toDateString(), 'due_date' => $r->due_date?->toDateString(),
            'status' => $r->status, 'response_notes' => $r->response_notes,
        ]);

        return response()->json(['data' => $rows, 'types' => array_keys(self::DSR_TYPES)]);
    }

    /** POST /my/data-requests {type: access|rectification|erasure, description} */
    public function storeDataRequest(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::DSR_TYPES))],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        abort_if(DataRequest::where('employee_id', $me->id)->where('type', $data['type'])->whereIn('status', ['pending', 'in_progress'])->exists(), 422,
            'You already have an open request of this type. HR will respond to it first.');

        $record = new DataRequest([
            'requester_name' => $me->full_name, 'requester_email' => $me->email, 'employee_id' => $me->id,
            'type' => $data['type'], 'description' => $data['description'], 'submitted_date' => today(),
            'due_date' => today()->addDays(self::DSR_TYPES[$data['type']]), 'status' => 'pending',
        ]);
        $record->company_id = $me->company_id;
        ComplianceService::saveWithNumber($record, 'reference', 'DSR');

        $this->service->notifyHr((int) $me->company_id, 'Data protection request',
            "{$me->full_name} made a data {$data['type']} request ({$record->reference}). Respond by {$record->due_date->format('d M Y')}.",
            '/compliance/data-protection', [$request->user()->id]);

        return response()->json(['message' => "Request {$record->reference} sent. HR must respond by {$record->due_date->format('j M Y')}.",
            'data' => ['id' => $record->id, 'reference' => $record->reference]], 201);
    }

    private function me(Request $request): Employee
    {
        $me = $request->user()->employee;
        abort_unless($me, 422, 'Your account is not linked to an employee record.');

        return $me;
    }
}
