<?php

namespace App\Modules\Claims\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Services\ClaimPresenter;
use App\Modules\Claims\Services\ClaimWorkflow;
use App\Services\Sms\SmsSender;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Finance payment queue: mark paid (single or bulk), reject, payment schedule CSV and confirmation PDF. */
class PaymentController extends Controller
{
    public function __construct(private ClaimWorkflow $workflow, private ClaimPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', MedicalClaim::HR_APPROVED);
        abort_unless(in_array($status, [MedicalClaim::HR_APPROVED, MedicalClaim::PAID, MedicalClaim::FINANCE_REJECTED], true), 422);

        $query = MedicalClaim::where('status', $status)
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('reference', 'like', '%'.$request->query('search').'%')
                ->orWhereHas('employee', fn ($e) => $e->search($request->query('search')))))
            ->when($request->filled('batch'), fn ($q) => $q->where('payment_batch', $request->query('batch')));

        $totals = (clone $query)->selectRaw('COUNT(*) as n, COALESCE(SUM(COALESCE(approved_amount, total_amount)),0) as amount')->first();

        $claims = $query->with(ClaimPresenter::LIST_WITH)->withCount('receipts')
            ->when($status === MedicalClaim::HR_APPROVED, fn ($q) => $q->orderBy('hr_decided_at'), fn ($q) => $q->orderByDesc('finance_decided_at'))
            ->paginate($request->integer('per_page', 50));

        return response()->json(array_merge($claims->toArray(), [
            'data' => $claims->getCollection()->map(fn ($c) => $this->presenter->row($c, $request->user()))->values(),
            'totals' => ['count' => (int) $totals->n, 'amount' => (float) $totals->amount],
            'methods' => MedicalClaim::PAYMENT_METHODS,
        ]));
    }

    public function pay(Request $request, MedicalClaim $claim): JsonResponse
    {
        abort_unless($this->workflow->canPay($request->user(), $claim), 403, 'You cannot pay this claim.');
        $claim = $this->workflow->markPaid($claim, $request->user(), $this->paymentData($request, single: true));

        return response()->json(['message' => 'Claim marked as paid.', 'data' => $this->presenter->row($claim->load(ClaimPresenter::LIST_WITH), $request->user())]);
    }

    public function reject(Request $request, MedicalClaim $claim): JsonResponse
    {
        abort_unless($this->workflow->canPay($request->user(), $claim), 403, 'You cannot reject this claim.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $claim = $this->workflow->financeReject($claim, $request->user(), $data['reason']);

        return response()->json(['message' => 'Claim rejected; the amount was restored to the employee\'s balance.', 'data' => $this->presenter->row($claim->load(ClaimPresenter::LIST_WITH), $request->user())]);
    }

    /** Pay several approved claims in one batch; returns the batch id for the payment schedule CSV. */
    public function bulkPay(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer']]);
        $payment = $this->paymentData($request);
        $batch = 'PB-'.now()->format('ymd-His');

        $claims = MedicalClaim::whereIn('id', $request->input('ids'))->with('employee')->get();
        $paid = [];
        $skipped = [];
        foreach ($claims as $claim) {
            if (! $this->workflow->canPay($request->user(), $claim)) {
                $skipped[] = ['id' => $claim->id, 'reference' => $claim->reference, 'reason' => $claim->status !== MedicalClaim::HR_APPROVED ? 'Not awaiting payment' : 'Not allowed'];

                continue;
            }
            $this->workflow->markPaid($claim, $request->user(), $payment, $batch);
            $paid[] = $claim->id;
        }
        if (! $paid) {
            throw ValidationException::withMessages(['ids' => 'None of the selected claims could be paid.']);
        }

        return response()->json([
            'message' => count($paid).' claim(s) marked as paid.',
            'batch' => $batch,
            'paid' => $paid,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Payment schedule CSV (M-Pesa bulk payment): ?batch=PB-... or ?ids=1,2,3 (claims awaiting payment or paid).
     */
    public function schedule(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('claims.pay'), 403);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        abort_unless($request->filled('batch') || $ids, 422, 'Choose a payment batch or claims.');

        $claims = MedicalClaim::with(['employee.branch:id,name', 'employee.department:id,name'])
            ->when($request->filled('batch'), fn ($q) => $q->where('payment_batch', $request->query('batch')))
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->whereIn('status', [MedicalClaim::HR_APPROVED, MedicalClaim::PAID])
            ->orderBy('id')->get();
        abort_if($claims->isEmpty(), 404, 'No payable claims found.');

        $name = 'claim-payment-schedule-'.($request->query('batch') ?: now()->format('Ymd-His')).'.csv';

        return response()->streamDownload(function () use ($claims) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Phone Number', 'Amount', 'Employee Name', 'Employee No', 'Claim Reference', 'Branch', 'Department', 'Status']);
            foreach ($claims as $c) {
                fputcsv($out, [
                    ltrim((string) SmsSender::normalise($c->employee?->phone), '+'),
                    number_format($c->effectiveAmount(), 2, '.', ''),
                    $c->employee?->full_name,
                    $c->employee?->employee_number,
                    $c->reference,
                    $c->employee?->branch?->name,
                    $c->employee?->department?->name,
                    $c->statusLabel(),
                ]);
            }
            fputcsv($out, ['TOTAL', number_format($claims->sum(fn ($c) => $c->effectiveAmount()), 2, '.', ''), $claims->count().' claims']);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Printable "Finance Payment Confirmation" for a paid claim — finance, HR and the claimant. */
    public function confirmation(Request $request, MedicalClaim $claim): Response
    {
        $user = $request->user();
        abort_unless($claim->status === MedicalClaim::PAID, 422, 'A payment confirmation is only available once the claim is paid.');
        abort_unless($claim->employee?->user_id === $user->id || $user->canAny(['claims.pay', 'claims.approve', 'claims.reports']), 403);

        $claim->load(['employee.branch', 'employee.department', 'employee.position', 'company', 'items', 'hrDecider:id,name', 'financeDecider:id,name']);

        $pdf = Pdf::loadView('claims::payment-confirmation', [
            'claim' => $claim,
            'company' => $claim->company,
            'employee' => $claim->employee,
            'categories' => MedicalClaim::CATEGORIES,
            'methods' => MedicalClaim::PAYMENT_METHODS,
            'claimantTypes' => MedicalClaim::CLAIMANT_TYPES,
            'mpesaPhone' => SmsSender::normalise($claim->employee?->phone),
            'currency' => $claim->company?->setting('currency') ?? 'KES',
        ])->setPaper('a4');

        $file = "payment-confirmation-{$claim->reference}.pdf";

        return $request->boolean('download') ? $pdf->download($file) : $pdf->stream($file);
    }

    /** Single payments need the method and (except cash) a transaction reference; a bulk batch is identified by its batch id. */
    private function paymentData(Request $request, bool $single = false): array
    {
        return $request->validate([
            'payment_method' => [$single ? 'required' : 'nullable', Rule::in(array_keys(MedicalClaim::PAYMENT_METHODS))],
            'payment_reference' => [$single ? 'required_unless:payment_method,cash' : 'nullable', 'nullable', 'string', 'max:100'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['payment_reference.required_unless' => 'Enter the M-Pesa or bank transaction reference.']) + ['payment_method' => 'mpesa'];
    }

    /** @internal kept for symmetry with the queue rows */
    private function sum(Collection $claims): float
    {
        return round($claims->sum(fn ($c) => $c->effectiveAmount()), 2);
    }
}
