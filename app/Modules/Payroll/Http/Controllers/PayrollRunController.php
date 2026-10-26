<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Support\PayrollSettings;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollRunItem;
use App\Modules\Payroll\Notifications\PayslipAvailable;
use App\Modules\Payroll\Services\PayrollReportService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\PayslipService;
use App\Modules\Payroll\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollRunController extends PayrollController
{
    public function __construct(private PayrollRunService $service, private PayrollReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $runs = PayrollRun::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->filled('year'), fn ($q) => $q->whereYear('period', $request->integer('year')))
            ->orderByDesc('period')->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20) ?: 20, 100));

        return response()->json($runs->through(fn ($r) => Presenter::run($r, $request->user())));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'pay_date' => ['nullable', 'date'],
            'type' => ['required', Rule::in(['regular', 'off_cycle'])],
            'off_cycle_reason' => ['nullable', 'required_if:type,off_cycle', Rule::in(array_keys(PayrollRun::OFF_CYCLE_REASONS))],
            'title' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'employee_ids' => ['array'],
            'employee_ids.*' => ['integer'],
            'employee_id' => ['nullable', 'required_if:off_cycle_reason,final_dues', 'integer'],
            'notice_pay' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'leave_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'exit_date' => ['nullable', 'date'],
            'calculate' => ['boolean'],
        ]);
        $company = $this->company($request);
        if (! empty($data['employee_id'])) {
            abort_unless(Employee::whereKey($data['employee_id'])->exists(), 422, 'Unknown employee.');
        }
        $run = $this->service->create($company, $data, $request->user());
        if ($request->boolean('calculate') || ($data['off_cycle_reason'] ?? null) === 'final_dues') {
            $run = $this->service->calculate($run, $request->user());
        }

        return response()->json(['data' => Presenter::run($run, $request->user()), 'message' => 'Payroll run created.'], 201);
    }

    public function show(Request $request, PayrollRun $run): JsonResponse
    {
        $previous = $this->reports->previousRun($run);
        $lines = PayrollRunEmployee::where('payroll_run_id', $run->id)->get();
        $byMethod = $lines->groupBy('payment_method')->map(fn ($g) => ['count' => $g->count(), 'amount' => round($g->sum('net_pay'), 2)]);

        return response()->json([
            'data' => Presenter::run($run, $request->user()),
            'previous' => $previous ? ['id' => $previous->id, 'title' => $previous->title, 'total_gross' => $previous->total_gross,
                'total_net' => $previous->total_net, 'employee_count' => $previous->employee_count, 'total_paye' => $previous->total_paye,
                'total_employer_cost' => $previous->total_employer_cost] : null,
            'statutory' => [
                'paye' => round($lines->sum('paye'), 2),
                'nssf' => round($lines->sum('nssf_employee') + $lines->sum('nssf_employer'), 2),
                'shif' => round($lines->sum('shif'), 2),
                'ahl' => round($lines->sum('ahl_employee') + $lines->sum('ahl_employer'), 2),
                'helb' => round($lines->sum('helb'), 2),
                'due_date' => $run->period?->copy()->addMonth()->day(9)->toDateString(),
            ],
            'payment_methods' => $byMethod,
            'cutoff' => $this->cutoff($run),
            'exceptions' => $lines->filter(fn ($l) => ! empty($l->flags))->load('employee:id,first_name,last_name,employee_number')
                ->flatMap(fn ($l) => collect($l->flags)->map(fn ($f) => $f + ['line_id' => $l->id, 'employee_name' => $l->employee?->full_name, 'employee_number' => $l->employee?->employee_number]))
                ->sortBy(fn ($f) => $f['severity'] === 'error' ? 0 : 1)->values(),
            'audit' => \Spatie\Activitylog\Models\Activity::where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)
                ->with('causer:id,name')->latest()->limit(30)->get()
                ->map(fn ($a) => ['description' => $a->description, 'causer' => $a->causer?->name, 'properties' => $a->properties, 'created_at' => $a->created_at]),
        ]);
    }

    /** Employee lines with variance vs the previous regular run (> threshold % flagged). */
    public function employees(Request $request, PayrollRun $run): JsonResponse
    {
        $previous = $this->reports->previousRun($run);
        $prev = $previous ? PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $previous->id)->get(['employee_id', 'gross_pay', 'net_pay'])->keyBy('employee_id') : collect();
        $search = trim((string) $request->query('search'));

        $query = PayrollRunEmployee::where('payroll_run_id', $run->id)
            ->with(['employee:id,first_name,last_name,employee_number,photo_path', 'department:id,name', 'branch:id,name'])
            ->when($search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e->search($search)))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->boolean('exceptions'), fn ($q) => $q->whereNotNull('flags'))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->query('payment_method')));

        $sort = in_array($request->query('sort'), ['gross_pay', 'net_pay', 'paye', 'total_deductions', 'basic_pay'], true) ? $request->query('sort') : null;
        $sort ? $query->orderBy($sort, $request->query('dir') === 'asc' ? 'asc' : 'desc') : $query->orderBy('employee_id');

        $page = $query->paginate(min($request->integer('per_page', 50) ?: 50, 500));
        $threshold = (float) PayrollSettings::get(Company::findOrFail($run->company_id), 'variance_threshold');

        return response()->json($page->through(function ($l) use ($prev, $previous, $threshold) {
            $p = $prev->get($l->employee_id);
            $pct = $p ? PayrollReportService::pct((float) $p->gross_pay, $l->gross_pay) : null;

            return Presenter::line($l, [
                'previous_gross' => $p?->gross_pay !== null ? (float) $p->gross_pay : null,
                'previous_net' => $p?->net_pay !== null ? (float) $p->net_pay : null,
                'variance_pct' => $pct,
                'variance_flag' => $previous && (! $p || $pct === null || abs($pct) > $threshold),
                'is_new' => $previous && ! $p,
            ]);
        }));
    }

    public function line(Request $request, PayrollRun $run, PayrollRunEmployee $line, PayslipService $payslips): JsonResponse
    {
        abort_unless((int) $line->payroll_run_id === $run->id, 404);
        $data = $payslips->data($line);
        // component-level variance vs previous run
        if ($previous = $this->reports->previousRun($run)) {
            $prevLine = PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $previous->id)->where('employee_id', $line->employee_id)->first();
            $prevItems = $prevLine ? PayrollRunItem::where('payroll_run_employee_id', $prevLine->id)->get()->groupBy('code')->map->sum('amount') : collect();
            $data['previous'] = $prevLine ? ['gross_pay' => $prevLine->gross_pay, 'net_pay' => $prevLine->net_pay, 'items' => $prevItems] : null;
        }
        $data['adjustments'] = PayrollAdjustment::with('component:id,code,name,category')->where('payroll_run_id', $run->id)
            ->where('employee_id', $line->employee_id)->get();
        $data['inputs'] = $line->inputs ?? [];

        return response()->json(['data' => $data]);
    }

    public function calculate(Request $request, PayrollRun $run): JsonResponse
    {
        $run = $this->service->calculate($run, $request->user());

        return response()->json(['data' => Presenter::run($run, $request->user()), 'message' => "Calculated {$run->employee_count} payslips."]);
    }

    public function review(Request $request, PayrollRun $run): JsonResponse
    {
        $run = $this->service->review($run, $request->user());

        return response()->json(['data' => Presenter::run($run, $request->user()), 'message' => 'Run marked as reviewed and sent for approval.']);
    }

    public function reopen(Request $request, PayrollRun $run): JsonResponse
    {
        $run = $this->service->reopen($run, $request->user(), $request->input('reason'));

        return response()->json(['data' => Presenter::run($run, $request->user()), 'message' => 'Run returned to calculated.']);
    }

    public function approve(Request $request, PayrollRun $run): JsonResponse
    {
        $run = $this->service->approve($run, $request->user());

        return response()->json(['data' => Presenter::run($run, $request->user()), 'message' => 'Payroll approved and locked.']);
    }

    public function reject(Request $request, PayrollRun $run): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $run = $this->service->reject($run, $request->user(), $data['reason']);

        return response()->json(['data' => Presenter::run($run, $request->user()), 'message' => 'Payroll sent back to the preparer.']);
    }

    public function markPaid(Request $request, PayrollRun $run): JsonResponse
    {
        $run = $this->service->markPaid($run, $request->user());

        return response()->json(['data' => Presenter::run($run, $request->user()), 'message' => 'Run marked as paid.']);
    }

    public function destroy(Request $request, PayrollRun $run): JsonResponse
    {
        abort_unless(in_array($run->status, ['draft', 'calculated'], true), 422, 'Only draft or calculated runs can be deleted.');
        activity('payroll')->performedOn($run)->causedBy($request->user())->withProperties(['title' => $run->title])->log('Payroll run deleted');
        $run->delete();

        return response()->json(['message' => 'Payroll run deleted.']);
    }

    /** Input cut-off for the run's month (pay calendar row, else the company cut-off day). Changes after it are allowed but flagged. */
    private function cutoff(PayrollRun $run): array
    {
        $company = Company::findOrFail($run->company_id);
        $date = $this->service->calendarRow($company, $run->period)?->cutoff_date?->toDateString()
            ?? $run->period->copy()->day(min((int) PayrollSettings::get($company, 'cutoff_day'), $run->period->daysInMonth))->toDateString();
        $passed = $run->type === 'regular' && now()->toDateString() > $date;

        return ['date' => $date, 'passed' => $passed,
            'message' => $passed ? 'The input cut-off ('.date('j M Y', strtotime($date)).') has passed. Late changes are logged; move non-urgent items to next month.' : null];
    }

    // ---------------------------------------------------------------- final dues

    /** Leavers (exited / transferred out, or with a scheduled exit) who can be paid final dues, with any final-dues run already created. */
    public function leavers(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $existing = PayrollRun::where('company_id', $company->id)->where('off_cycle_reason', 'final_dues')->get(['id', 'exit_employee_id', 'status'])->keyBy('exit_employee_id');
        $scheduled = \Illuminate\Support\Facades\DB::table('employee_exits')->where('company_id', $company->id)
            ->where('status', 'scheduled')->whereNotNull('exit_date')->select('employee_id');
        $rows = Employee::withoutGlobalScope('company')->where('company_id', $company->id)
            ->where(fn ($q) => $q->whereIn('status', ['exited', 'transferred'])
                ->orWhere(fn ($w) => $w->whereIn('status', ['active', 'suspended'])->whereIn('id', $scheduled)))
            ->whereIn('id', PayrollPayProfile::withoutGlobalScopes()->where('company_id', $company->id)->select('employee_id'))
            ->with(['exitRecord', 'department:id,name'])->orderByDesc('updated_at')->limit(300)->get()
            ->map(function (Employee $e) use ($existing) {
                $end = $this->service->finalDuesDate($e);
                $run = $existing->get($e->id);

                return [
                    'id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number, 'status' => $e->status,
                    'scheduled' => ! in_array($e->status, ['exited', 'transferred'], true),
                    'department' => $e->department?->name, 'exit_date' => $end?->toDateString(),
                    'final_dues_run' => $run ? ['id' => $run->id, 'status' => $run->status] : null,
                ];
            })
            ->sortByDesc('exit_date')->values();

        return response()->json(['data' => $rows]);
    }

    // ---------------------------------------------------------------- one-off adjustments

    public function adjustments(PayrollRun $run): JsonResponse
    {
        $rows = PayrollAdjustment::with(['component:id,code,name,category', 'employee:id,first_name,last_name,employee_number', 'creator:id,name'])
            ->where('payroll_run_id', $run->id)->latest()->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'employee_id' => $a->employee_id, 'employee_name' => $a->employee?->full_name, 'employee_number' => $a->employee?->employee_number,
                'component' => $a->component, 'amount' => $a->amount, 'description' => $a->description,
                'created_by' => $a->creator?->name, 'created_at' => $a->created_at,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** @return array{0: array, 1: PayrollComponent} */
    private function validateAdjustment(Request $request, PayrollRun $run): array
    {
        abort_unless($run->isEditable(), 422, 'This run is locked. Use an off-cycle run for corrections.');
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'component_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless(Employee::withoutGlobalScope('company')->where('company_id', $run->company_id)->whereKey($data['employee_id'])->exists(), 422, 'Employee not in this company.');
        PayProfileController::assertNotOwnPay($request, (int) $data['employee_id']);
        $component = PayrollComponent::withoutGlobalScopes()->where('company_id', $run->company_id)->findOrFail($data['component_id']);
        abort_if($component->category === 'employer_contribution', 422, 'Employer contributions cannot be entered as one-off items.');

        return [$data, $component];
    }

    private function cutoffNote(PayrollRun $run): string
    {
        $cutoff = $this->cutoff($run);

        return $cutoff['passed'] ? ' Note: entered after the '.date('j M', strtotime($cutoff['date'])).' input cut-off.' : '';
    }

    public function storeAdjustment(Request $request, PayrollRun $run): JsonResponse
    {
        [$data, $component] = $this->validateAdjustment($request, $run);

        $adj = PayrollAdjustment::create($data + ['payroll_run_id' => $run->id, 'created_by' => $request->user()->id]);
        $this->service->markDirty($run);
        activity('payroll')->performedOn($run)->causedBy($request->user())
            ->withProperties(['employee_id' => $adj->employee_id, 'component' => $component->code, 'amount' => $adj->amount, 'after_cutoff' => $this->cutoff($run)['passed']])
            ->log('One-off item added');

        return response()->json(['data' => $adj, 'message' => 'One-off item added. Recalculate the run to apply it.'.$this->cutoffNote($run)], 201);
    }

    public function updateAdjustment(Request $request, PayrollRun $run, PayrollAdjustment $adjustment): JsonResponse
    {
        abort_unless((int) $adjustment->payroll_run_id === $run->id, 404);
        [$data, $component] = $this->validateAdjustment($request, $run);
        $before = $adjustment->only(['employee_id', 'component_id', 'amount', 'description']);
        $adjustment->update($data);
        $this->service->markDirty($run);
        activity('payroll')->performedOn($run)->causedBy($request->user())
            ->withProperties(['adjustment_id' => $adjustment->id, 'before' => $before, 'after' => $data, 'component' => $component->code, 'after_cutoff' => $this->cutoff($run)['passed']])
            ->log('One-off item changed');

        return response()->json(['data' => $adjustment, 'message' => 'One-off item updated. Recalculate the run to apply it.'.$this->cutoffNote($run)]);
    }

    public function destroyAdjustment(Request $request, PayrollRun $run, PayrollAdjustment $adjustment): JsonResponse
    {
        abort_unless((int) $adjustment->payroll_run_id === $run->id, 404);
        abort_unless($run->isEditable(), 422, 'This run is locked.');
        PayProfileController::assertNotOwnPay($request, (int) $adjustment->employee_id);
        $adjustment->delete();
        $this->service->markDirty($run);
        activity('payroll')->performedOn($run)->causedBy($request->user())->withProperties(['adjustment_id' => $adjustment->id])->log('One-off item removed');

        return response()->json(['message' => 'One-off item removed.']);
    }

    // ---------------------------------------------------------------- outputs

    public function emailPayslips(Request $request, PayrollRun $run): JsonResponse
    {
        abort_unless($run->isLocked(), 422, 'Payslips can only be emailed after approval.');
        // One queued email per employee (PayslipAvailable is ShouldQueue); the PDF is rendered by the queue worker.
        $count = 0;
        $skipped = 0;
        PayrollRunEmployee::with('employee.user')->where('payroll_run_id', $run->id)
            ->when(! $request->boolean('resend'), fn ($q) => $q->whereNull('emailed_at'))
            ->each(function ($line) use (&$count, &$skipped) {
                if ($user = $line->employee?->user) {
                    $user->notify(new PayslipAvailable($line, withPdf: true));
                    $count++;
                } else {
                    $skipped++;
                }
            });
        $run->update(['payslips_emailed_at' => now()]);
        activity('payroll')->performedOn($run)->causedBy($request->user())->withProperties(['count' => $count, 'skipped' => $skipped])->log('Payslips emailed');

        return response()->json([
            'message' => "{$count} payslip email(s) queued.".($skipped ? " {$skipped} employee(s) have no login and were skipped." : ''),
            'count' => $count, 'skipped' => $skipped,
        ]);
    }

    public function linePdf(PayrollRun $run, PayrollRunEmployee $line, PayslipService $payslips)
    {
        abort_unless((int) $line->payroll_run_id === $run->id, 404);

        return $payslips->pdf($line)->stream($payslips->filename($line));
    }

    public function export(Request $request, PayrollRun $run, string $type)
    {
        abort_if($run->status === 'draft', 422, 'Calculate the run first.');
        $table = match (true) {
            in_array($type, PayrollReportService::RUN_REPORTS, true) => $this->reports->runReport($run, $type),
            array_key_exists($type, PayrollReportService::RETURNS) => $this->reports->statutoryReturn($run, $type),
            array_key_exists($type, PayrollReportService::PAYMENT_FILES) => $this->reports->paymentFile($run, $type),
            $type === 'journal' => $this->reports->journal($run),
            default => abort(404),
        };
        if (array_key_exists($type, PayrollReportService::PAYMENT_FILES)) {
            abort_unless($run->isLocked(), 422, 'Payment files are available after approval.');
        }
        activity('payroll')->performedOn($run)->causedBy($request->user())->withProperties(['type' => $type])->log('Payroll export downloaded');

        return $request->query('format') === 'json'
            ? response()->json($table)
            : $this->reports->csv($table, ! array_key_exists($type, PayrollReportService::PAYMENT_FILES));
    }
}
