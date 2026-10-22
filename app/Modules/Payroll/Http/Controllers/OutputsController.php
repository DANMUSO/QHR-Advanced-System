<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\PayrollPaymentFile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\PayrollReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Company-wide payroll outputs: bank / M-Pesa payment files, statutory returns (P9A, P10, NSSF, SHIF, AHL, HELB) and variance. */
class OutputsController extends PayrollController
{
    public const RETURN_TYPES = ['p9a', 'p10', 'paye', 'nssf', 'shif', 'ahl', 'helb'];

    public function __construct(private PayrollReportService $reports) {}

    // ---------------------------------------------------------------- payment files

    /** One bank file and one M-Pesa file per approved / paid run (generated on first listing). */
    public function paymentFiles(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->syncPaymentFiles($company->id, $request->user()->id);

        $rows = PayrollPaymentFile::with(['run:id,title,period,status,type', 'generator:id,name'])
            ->where('company_id', $company->id)
            ->when($request->filled('run_id'), fn ($q) => $q->where('payroll_run_id', $request->integer('run_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->latest('generated_at')->latest('id')->limit(200)->get()
            ->map(fn (PayrollPaymentFile $f) => [
                'id' => $f->id, 'payroll_run_id' => $f->payroll_run_id, 'run_title' => $f->run?->title,
                'period' => $f->run?->period?->format('Y-m'), 'run_status' => $f->run?->status,
                'type' => $f->type, 'type_label' => PayrollReportService::PAYMENT_FILES[$f->type] ?? $f->type,
                'bank_name' => $f->provider, 'records' => (int) $f->records, 'total_amount' => $f->total_amount,
                'filename' => $f->filename, 'status' => $f->status, 'generated_by' => $f->generator?->name,
                'generated_at' => $f->generated_at?->toDateTimeString(), 'download_count' => (int) $f->download_count,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function downloadPaymentFile(Request $request, PayrollPaymentFile $file)
    {
        $run = PayrollRun::findOrFail($file->payroll_run_id);
        abort_unless($run->isLocked(), 422, 'Payment files are available after approval.');
        $table = $this->reports->paymentFile($run, $file->type);
        $table['filename'] = $file->filename;
        $file->increment('download_count');
        if ($file->status === 'generated') {
            $file->update(['status' => 'downloaded']);
        }
        activity('payroll')->performedOn($run)->causedBy($request->user())
            ->withProperties(['type' => $file->type, 'records' => $file->records, 'total' => $file->total_amount])->log('Payment file downloaded');

        return $this->reports->csv($table);
    }

    private function syncPaymentFiles(int $companyId, int $userId): void
    {
        $runs = PayrollRun::where('company_id', $companyId)->whereIn('status', ['approved', 'paid'])->get();
        foreach ($runs as $run) {
            $groups = PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('net_pay', '>', 0)
                ->selectRaw('payment_method, COUNT(*) c, SUM(net_pay) total')->groupBy('payment_method')->get()->keyBy('payment_method');
            foreach (array_keys(PayrollReportService::PAYMENT_FILES) as $type) {
                $g = $groups->get($type);
                if (! $g || ! $g->c) {
                    continue;
                }
                $file = PayrollPaymentFile::firstOrNew(['company_id' => $companyId, 'payroll_run_id' => $run->id, 'type' => $type]);
                $file->fill([
                    'provider' => $type === 'mpesa' ? 'Safaricom M-Pesa (B2C bulk)' : 'Bank EFT / RTGS',
                    'records' => (int) $g->c, 'total_amount' => round((float) $g->total, 2),
                    'filename' => ($type === 'mpesa' ? 'mpesa-bulk-' : 'bank-transfer-').$run->period->format('Y-m')
                        .($run->type === 'off_cycle' ? '-run'.$run->id : '').'.csv',
                ]);
                if (! $file->exists) {
                    $file->status = 'generated';
                    $file->generated_by = $userId;
                    $file->generated_at = $run->approved_at ?? now();
                }
                $file->save();
            }
        }
    }

    // ---------------------------------------------------------------- statutory returns

    public function returns(Request $request): JsonResponse
    {
        $table = $this->returnTable($request);

        return response()->json($table + ['data' => $table['rows']]);
    }

    public function exportReturns(Request $request)
    {
        $table = $this->returnTable($request);
        activity('payroll')->causedBy($request->user())->withProperties(['type' => $request->query('type'), 'year' => $request->query('year')])->log('Statutory return exported');

        return $this->reports->csv($table, true);
    }

    private function returnTable(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(self::RETURN_TYPES)],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'run_id' => ['nullable', 'integer'],
        ]);
        $company = $this->company($request);
        $type = $data['type'] ?? 'p9a';

        // A single run's monthly schedule (P10 / NSSF / SHIF / AHL / HELB file format).
        if (! empty($data['run_id'])) {
            $run = PayrollRun::where('company_id', $company->id)->findOrFail($data['run_id']);

            return $this->reports->statutoryReturn($run, in_array($type, ['p9a', 'p10'], true) ? 'paye' : $type);
        }

        return $this->reports->annualReturn($company->id, (int) ($data['year'] ?? now()->year), $type, $data['month'] ?? null);
    }

    // ---------------------------------------------------------------- variance

    public function variance(Request $request): JsonResponse
    {
        return response()->json($this->varianceData($request));
    }

    public function exportVariance(Request $request)
    {
        return $this->reports->csv($this->reports->varianceTable($this->varianceData($request)), true);
    }

    private function varianceData(Request $request): array
    {
        $data = $request->validate([
            'current' => ['nullable', 'integer'], 'previous' => ['nullable', 'integer'],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);
        $company = $this->company($request);
        $runs = PayrollRun::where('company_id', $company->id);
        $current = ! empty($data['current'])
            ? (clone $runs)->findOrFail($data['current'])
            : (clone $runs)->where('type', 'regular')->where('status', '!=', 'draft')->orderByDesc('period')->first();
        abort_unless($current, 422, 'There is no calculated payroll run to compare yet.');
        $previous = ! empty($data['previous']) ? (clone $runs)->findOrFail($data['previous']) : $this->reports->previousRun($current);

        return $this->reports->variance($current, $previous, (float) ($data['threshold'] ?? \App\Modules\Payroll\Support\PayrollSettings::get($company, 'variance_threshold')));
    }
}
