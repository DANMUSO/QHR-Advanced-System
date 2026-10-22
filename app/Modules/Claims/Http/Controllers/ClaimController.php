<?php

namespace App\Modules\Claims\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDependant;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Models\MedicalClaimReceipt;
use App\Modules\Claims\Services\ClaimLimits;
use App\Modules\Claims\Services\ClaimPresenter;
use App\Modules\Claims\Services\ClaimWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Employee self-service: submit, edit, cancel and track own medical claims. */
class ClaimController extends Controller
{
    public const MAX_AGE_DAYS = 90;

    public function __construct(
        private ClaimLimits $limits,
        private ClaimWorkflow $workflow,
        private ClaimPresenter $presenter,
    ) {}

    /** Balances, dependants and form options for the current employee. ?date=Y-m-d previews another month. */
    public function summary(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $date = $request->filled('date') ? Carbon::parse($request->query('date')) : today();
        $exclude = $request->integer('exclude') ?: null;

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->full_name, 'phone' => $employee->phone],
            'balance' => $this->limits->balanceFor($employee, $date, $exclude),
            'year_balance' => $this->limits->balanceFor($employee, today()),
            'dependants' => EmployeeDependant::where('employee_id', $employee->id)->orderBy('name')->get(['id', 'name', 'relationship', 'date_of_birth']),
            'categories' => MedicalClaim::CATEGORIES,
            'claimant_types' => MedicalClaim::CLAIMANT_TYPES,
            'max_age_days' => self::MAX_AGE_DAYS,
            'currency' => $employee->company?->setting('currency') ?? 'KES',
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $year = $request->integer('year') ?: null;
        $claims = MedicalClaim::where('employee_id', $employee->id)
            ->when($year, fn ($q) => $q->inYear($year))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->with(ClaimPresenter::LIST_WITH)->withCount('receipts')
            ->orderByDesc('claim_date')->orderByDesc('id')
            ->get();

        $years = MedicalClaim::where('employee_id', $employee->id)->selectRaw('DISTINCT YEAR(claim_date) as y')->pluck('y')
            ->push((int) now()->year)->unique()->sortDesc()->values();

        return response()->json([
            'data' => $claims->map(fn ($c) => $this->presenter->row($c, $request->user()))->values(),
            'years' => $years,
        ]);
    }

    public function show(Request $request, MedicalClaim $claim): JsonResponse
    {
        abort_unless($this->workflow->canView($request->user(), $claim), 403);

        return response()->json(['data' => $this->presenter->detail(
            $claim->load(ClaimPresenter::LIST_WITH),
            $request->user(),
            $this->limits->balanceFor($claim->employee, $claim->claim_date, $claim->id),
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $data = $this->validated($request, $employee, null);

        $claim = DB::transaction(function () use ($request, $employee, $data) {
            $claim = $this->workflow->submit($employee, $data['claim'], $request->user());
            $claim->items()->createMany($data['items']);
            $this->storeReceipts($request, $claim);

            return $claim;
        });

        return response()->json(['message' => 'Claim submitted for approval.', 'data' => $this->presenter->row($claim->load(ClaimPresenter::LIST_WITH), $request->user())], 201);
    }

    public function update(Request $request, MedicalClaim $claim): JsonResponse
    {
        abort_unless($this->workflow->canEdit($request->user(), $claim), 403, 'Only your own pending claims can be edited.');
        $employee = $claim->employee;
        $data = $this->validated($request, $employee, $claim);

        DB::transaction(function () use ($request, $claim, $data) {
            $before = (float) $claim->total_amount;
            $claim->fill($data['claim'])->save();
            $claim->items()->delete();
            $claim->items()->createMany($data['items']);
            $this->storeReceipts($request, $claim);
            $this->workflow->log($claim, $request->user(), 'edited', MedicalClaim::SUBMITTED, MedicalClaim::SUBMITTED, null,
                ['previous_amount' => $before, 'amount' => (float) $claim->total_amount]);
        });

        return response()->json(['message' => 'Claim updated.', 'data' => $this->presenter->row($claim->fresh(ClaimPresenter::LIST_WITH), $request->user())]);
    }

    public function cancel(Request $request, MedicalClaim $claim): JsonResponse
    {
        abort_unless($this->workflow->canEdit($request->user(), $claim), 403, 'Only your own pending claims can be cancelled.');
        $this->workflow->cancel($claim, $request->user());

        return response()->json(['message' => 'Claim cancelled.']);
    }

    public function destroyReceipt(Request $request, MedicalClaim $claim, MedicalClaimReceipt $receipt): JsonResponse
    {
        abort_unless($receipt->medical_claim_id === $claim->id, 404);
        abort_unless($this->workflow->canEdit($request->user(), $claim), 403);
        abort_if($claim->receipts()->count() <= 1, 422, 'A claim needs at least one receipt. Upload another before removing this one.');

        Storage::disk('local')->delete($receipt->path);
        $receipt->delete();
        $this->workflow->log($claim, $request->user(), 'receipt_removed', null, null, $receipt->original_name);

        return response()->json(['message' => 'Receipt removed.']);
    }

    /** Authorised receipt download; ?inline=1 previews in the browser. */
    public function receipt(Request $request, MedicalClaim $claim, MedicalClaimReceipt $receipt): StreamedResponse
    {
        abort_unless($receipt->medical_claim_id === $claim->id, 404);
        abort_unless($this->workflow->canView($request->user(), $claim), 403);
        abort_unless(Storage::disk('local')->exists($receipt->path), 404, 'The receipt file is missing.');

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return Storage::disk('local')->response($receipt->path, $receipt->original_name, [
            'Content-Type' => $receipt->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }

    /* ------------------------------------------------------------------ helpers */

    private function me(Request $request): Employee
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            abort(422, 'Your account is not linked to an employee record, so you cannot submit medical claims.');
        }

        return $employee;
    }

    /** @return array{claim: array, items: array} */
    private function validated(Request $request, Employee $employee, ?MedicalClaim $claim): array
    {
        $existingReceipts = $claim ? $claim->receipts()->count() : 0;
        $v = $request->validate([
            'claimant_type' => ['required', Rule::in(['self', 'dependant', 'other'])],
            'dependant_id' => ['nullable', 'required_if:claimant_type,dependant', 'integer',
                Rule::exists('employee_dependants', 'id')->where('employee_id', $employee->id)],
            'other_name' => ['nullable', 'required_if:claimant_type,other', 'string', 'max:150'],
            'other_relationship' => ['nullable', Rule::in(['spouse', 'child', 'parent', 'other'])],
            'claim_date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(self::MAX_AGE_DAYS)->toDateString()],
            'provider_name' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.category' => ['required', Rule::in(array_keys(MedicalClaim::CATEGORIES))],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'receipts' => [$existingReceipts ? 'nullable' : 'required', 'array', 'max:10'],
            'receipts.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
        ], [
            'claim_date.before_or_equal' => 'The claim date cannot be in the future.',
            'claim_date.after_or_equal' => 'Claims must be submitted within '.self::MAX_AGE_DAYS.' days of treatment.',
            'receipts.required' => 'Attach at least one receipt.',
            'receipts.*.max' => 'Each receipt must be 5 MB or smaller.',
            'receipts.*.mimes' => 'Receipts must be PDF, JPG or PNG files.',
            'receipts.*.mimetypes' => 'Receipts must be PDF, JPG or PNG files.',
            'dependant_id.required_if' => 'Choose the dependant this claim is for.',
            'other_name.required_if' => "Enter the dependant's name.",
        ]);

        $items = collect($v['items'])->map(fn ($i) => [
            'category' => $i['category'], 'description' => $i['description'] ?? null, 'amount' => round((float) $i['amount'], 2),
        ])->values()->all();
        $total = round(array_sum(array_column($items, 'amount')), 2);
        $date = Carbon::parse($v['claim_date']);

        [$type, $dependantId, $name] = match ($v['claimant_type']) {
            'dependant' => (function () use ($v) {
                $d = EmployeeDependant::find($v['dependant_id']);

                return [$this->normaliseRelationship($d->relationship), $d->id, $d->name];
            })(),
            'other' => [$v['other_relationship'] ?? 'other', null, $v['other_name']],
            default => ['self', null, $employee->full_name],
        };

        $errors = $this->limits->violations($employee, $total, $date, $claim?->id);
        if ($errors) {
            throw ValidationException::withMessages(['total' => $errors]);
        }

        return [
            'claim' => [
                'claimant_type' => $type, 'dependant_id' => $dependantId, 'claimant_name' => $name,
                'claim_date' => $date->toDateString(), 'provider_name' => $v['provider_name'],
                'notes' => $v['notes'] ?? null, 'total_amount' => $total,
            ],
            'items' => $items,
        ];
    }

    private function normaliseRelationship(?string $relationship): string
    {
        $r = strtolower((string) $relationship);

        return match (true) {
            in_array($r, ['spouse', 'wife', 'husband', 'partner'], true) => 'spouse',
            in_array($r, ['child', 'son', 'daughter', 'children'], true) => 'child',
            in_array($r, ['parent', 'mother', 'father'], true) => 'parent',
            default => 'other',
        };
    }

    private function storeReceipts(Request $request, MedicalClaim $claim): void
    {
        /** @var UploadedFile $file */
        foreach ((array) $request->file('receipts', []) as $file) {
            $path = $file->store("claims/{$claim->company_id}/{$claim->id}", 'local');
            $claim->receipts()->create([
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }
    }
}
