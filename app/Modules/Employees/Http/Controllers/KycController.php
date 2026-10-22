<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Models\KycCheck;
use App\Modules\Employees\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KycController extends Controller
{
    public function __construct(private KycService $kyc) {}

    /** GET /employees/kyc?search=&status= — current staff with their latest identity and background check. */
    public function index(Request $request): JsonResponse
    {
        $latest = fn (string $type) => KycCheck::select('status')->whereColumn('employee_kyc_checks.employee_id', 'employees.id')
            ->where('check_type', $type)->latest('id')->limit(1);

        $query = Employee::query()->whereIn('status', ['active', 'suspended'])->search($request->query('search'))
            ->with(['department:id,name'])
            ->addSelect(['kyc_status' => $latest('identity'), 'background_status' => $latest('background')])
            ->when($request->query('status'), function ($q, $s) use ($latest) {
                $s === 'not_submitted'
                    ? $q->whereNotExists(fn ($w) => $w->from('employee_kyc_checks')->whereColumn('employee_kyc_checks.employee_id', 'employees.id')->where('check_type', 'identity'))
                    : $q->where($latest('identity'), $s);
            })
            ->orderBy('first_name')->orderBy('last_name');

        $page = $query->paginate(min($request->integer('per_page', 50) ?: 50, 200));
        $checks = KycCheck::whereIn('employee_id', collect($page->items())->pluck('id'))->with('verifier:id,name')->latest('id')->get()->groupBy('employee_id');

        return response()->json($page->through(function (Employee $e) use ($checks) {
            $mine = $checks[$e->id] ?? collect();
            $identity = $mine->firstWhere('check_type', 'identity');
            $background = $mine->firstWhere('check_type', 'background');

            return [
                'id' => $e->id,
                'employee_name' => $e->full_name,
                'employee_number' => $e->employee_number,
                'photo_url' => $e->photo_url,
                'department' => $e->department?->name,
                'national_id' => $e->national_id,
                'kra_pin' => $e->kra_pin,
                'nssf_number' => $e->nssf_number,
                'shif_number' => $e->shif_number,
                'kyc_status' => $identity?->status ?? 'not_submitted',
                'issues' => $identity?->result['issues'] ?? [],
                'background_check' => $background?->status ?? 'not_submitted',
                'background_reference' => $background?->reference,
                'last_verified' => $identity?->verified_at?->toDateString(),
                'verified_by' => $identity?->verifier?->name,
            ];
        }));
    }

    /** GET /employees/{id}/kyc — check history for one employee. */
    public function show(int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);

        return response()->json(['data' => KycCheck::where('employee_id', $e->id)->with('verifier:id,name')->latest('id')->get()
            ->map(fn (KycCheck $c) => [
                'id' => $c->id, 'check_type' => $c->check_type, 'status' => $c->status, 'provider' => $c->provider,
                'reference' => $c->reference, 'checks' => $c->result['checks'] ?? [], 'issues' => $c->result['issues'] ?? [],
                'notes' => $c->notes, 'verified_by' => $c->verifier?->name, 'verified_at' => $c->verified_at,
            ])]);
    }

    /**
     * POST /employees/{id}/kyc/verify
     *   (no body | type=identity)   run the identity verification
     *   type=background, status=verified|failed|pending, reference, notes   record a background check outcome
     */
    public function verify(Request $request, int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['identity', 'background'])],
            'status' => ['nullable', 'required_if:type,background', Rule::in(['pending', 'verified', 'failed'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (($data['type'] ?? 'identity') === 'background') {
            $check = $this->kyc->recordBackground($e, $data['status'], $data['reference'] ?? null, $data['notes'] ?? null, $request->user());

            return response()->json(['data' => ['status' => $check->status], 'message' => 'Background check recorded.']);
        }

        $check = $this->kyc->verifyIdentity($e, $request->user(), $data['notes'] ?? null);
        $issues = $check->result['issues'] ?? [];

        return response()->json([
            'data' => ['status' => $check->status, 'issues' => $issues],
            'message' => $check->status === 'verified' ? "{$e->full_name}'s identity details are verified." : 'Verification failed: '.implode('; ', $issues),
        ], $check->status === 'verified' ? 200 : 422);
    }
}
