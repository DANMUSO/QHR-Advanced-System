<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Models\Grievance;
use App\Modules\Compliance\Services\ComplianceService;
use App\Modules\Engagement\Support\AuthorHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employee self-service grievances (/api/my/grievances). Anonymous grievances store no employee_id, only the author
 * hash, so HR sees "Anonymous" while the author still sees the thread and its status.
 */
class MyGrievanceController extends Controller
{
    public function __construct(private ComplianceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        abort_unless($me, 422, 'Your account is not linked to an employee record.');
        $hash = AuthorHash::for($request->user());

        $rows = Grievance::where(fn ($q) => $q->where('employee_id', $me->id)->orWhere('author_hash', $hash))
            ->with('assignedTo:id,first_name,last_name')->latest('id')->get();

        return response()->json(['data' => $rows->map(fn (Grievance $g) => $this->row($g)), 'categories' => Grievance::CATEGORIES]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        abort_unless($me, 422, 'Your account is not linked to an employee record.');
        $data = $request->validate([
            'category' => ['nullable', Rule::in(Grievance::CATEGORIES)],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'anonymous' => ['sometimes', 'boolean'],
        ]);
        $anonymous = (bool) ($data['anonymous'] ?? false);

        $grievance = new Grievance([
            'employee_id' => $anonymous ? null : $me->id,
            'author_hash' => AuthorHash::for($request->user()),
            'anonymous' => $anonymous,
            'category' => $data['category'] ?? 'workplace',
            'subject' => $data['subject'],
            'description' => $data['description'],
            'filed_date' => today(),
            'status' => 'open',
        ]);
        $grievance->company_id = $me->company_id;
        // Anonymous filings must not be traceable through the audit trail either.
        if ($anonymous) {
            $grievance->disableLogging();
        }
        ComplianceService::saveWithNumber($grievance, 'reference', 'GRV');
        $grievance->enableLogging();

        $who = $anonymous ? 'An anonymous employee' : $me->full_name;
        $this->service->notifyHr((int) $me->company_id, 'New grievance filed',
            "{$who} filed grievance {$grievance->reference}: {$grievance->subject}", '/compliance?tab=grievances', [$request->user()->id]);
        $this->service->notifyEmployee($me, 'Grievance received',
            "Your grievance {$grievance->reference} was received. HR will update you on its progress.");

        return response()->json(['message' => 'Grievance submitted.', 'data' => $this->row($grievance->fresh('assignedTo'))], 201);
    }

    private function row(Grievance $g): array
    {
        return [
            'id' => $g->id, 'reference' => $g->reference, 'category' => $g->category, 'subject' => $g->subject,
            'description' => $g->description, 'anonymous' => (bool) $g->anonymous, 'status' => $g->status,
            'resolution' => $g->resolution, 'filed_date' => $g->filed_date?->toDateString(),
            'assigned_to' => $g->assignedTo?->full_name, 'updated_at' => $g->updated_at?->toIso8601String(),
        ];
    }
}
