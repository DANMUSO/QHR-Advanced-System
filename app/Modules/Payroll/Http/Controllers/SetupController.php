<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\PayrollCalendar;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\StatutoryRate;
use App\Modules\Payroll\Services\Calculation\CalculationInput;
use App\Modules\Payroll\Services\Calculation\PayrollCalculator;
use App\Modules\Payroll\Services\StatutoryRateRepository;
use App\Modules\Payroll\Support\PayrollSettings;
use App\Modules\Payroll\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SetupController extends PayrollController
{
    // ---------------------------------------------------------------- statutory tables

    public function statutory(Request $request, StatutoryRateRepository $repo): JsonResponse
    {
        $asOf = $request->query('date', now()->toDateString());
        // Rates are global (statute applies to every company); a fresh install without rows gets the Kenya defaults.
        if (! StatutoryRate::exists()) {
            \App\Modules\Payroll\Support\PayrollDefaults::seedStatutory();
        }
        $rows = StatutoryRate::orderBy('type')->orderByDesc('effective_from')->orderBy('lower_limit')->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $r->toArray() + [
                'type_label' => StatutoryRate::TYPES[$r->type] ?? $r->type,
                'in_force' => $r->effective_from->toDateString() <= $asOf && (! $r->effective_to || $r->effective_to->toDateString() >= $asOf),
            ]),
            'types' => StatutoryRate::TYPES,
            'as_of' => $asOf,
            'effective' => $repo->forDate($asOf)->toArray(),
            'notice' => 'Rates are applied by effective date. Defaults follow the Finance Act 2023 (PAYE), the NSSF Act 2013 phase-in (Year 4 from February 2026: LEL KES 9,000 / UEL KES 108,000, 6% + 6%), SHIF (2.75%, min KES 300) and the Affordable Housing Levy (1.5% + 1.5%). Always confirm these rates against the official NSSF, KRA and SHA notices before running payroll.',
        ]);
    }

    private function validateRate(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(array_keys(StatutoryRate::TYPES))],
            'name' => ['required', 'string', 'max:150'],
            'lower_limit' => ['nullable', 'numeric', 'min:0'],
            'upper_limit' => ['nullable', 'numeric', 'min:0', 'gte:lower_limit'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'employer_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'cap' => ['nullable', 'numeric', 'min:0'],
            'tax_deductible' => ['boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $data = $this->validateRate($request);
        // Closing the previous open row of a single-row type keeps one rate in force at a time.
        if ($data['type'] !== 'paye_band' && $request->boolean('close_previous', true)) {
            StatutoryRate::where('type', $data['type'])->whereNull('effective_to')->whereDate('effective_from', '<', $data['effective_from'])
                ->each(fn ($r) => $r->update(['effective_to' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString()]));
        }
        $rate = StatutoryRate::create($data);

        return response()->json(['data' => $rate, 'message' => 'Statutory rate added.'], 201);
    }

    public function updateRate(Request $request, StatutoryRate $rate): JsonResponse
    {
        $rate->update($this->validateRate($request));

        return response()->json(['data' => $rate, 'message' => 'Statutory rate updated.']);
    }

    public function destroyRate(StatutoryRate $rate): JsonResponse
    {
        $rate->delete();

        return response()->json(['message' => 'Statutory rate deleted.']);
    }

    /** Quick calculator for the statutory screen. */
    public function preview(Request $request, StatutoryRateRepository $repo, PayrollCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'basic' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'allowances' => ['nullable', 'numeric', 'min:0'],
            'non_taxable' => ['nullable', 'numeric', 'min:0'],
            'pension_percent' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'insurance_premium' => ['nullable', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
        ]);
        $components = [];
        if (! empty($data['allowances'])) {
            $components[] = ['code' => 'ALLOW', 'name' => 'Taxable allowances', 'category' => 'earning', 'calculation' => 'fixed', 'amount' => $data['allowances'], 'taxable' => true];
        }
        if (! empty($data['non_taxable'])) {
            $components[] = ['code' => 'NONTAX', 'name' => 'Non-taxable allowances', 'category' => 'earning', 'calculation' => 'fixed', 'amount' => $data['non_taxable'], 'taxable' => false];
        }
        if (! empty($data['insurance_premium'])) {
            $components[] = ['code' => 'INS', 'name' => 'Insurance premium', 'category' => 'deduction', 'calculation' => 'fixed', 'amount' => $data['insurance_premium'], 'role' => 'insurance_premium'];
        }
        $date = $data['date'] ?? now()->toDateString();
        $result = $calculator->calculate(CalculationInput::make([
            'basicSalary' => (float) $data['basic'],
            'periodStart' => CarbonImmutable::parse($date)->startOfMonth(),
            'periodEnd' => CarbonImmutable::parse($date)->endOfMonth()->startOfDay(),
            'components' => $components,
            'pensionEmployeePercent' => (float) ($data['pension_percent'] ?? 0),
        ]), $repo->forDate($date));

        return response()->json(['data' => $result]);
    }

    // ---------------------------------------------------------------- company settings

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => PayrollSettings::all($this->company($request))]);
    }

    /** PUT /payroll/settings/own-pay-signoff {allowed} — HR only (settings.manage); audited. */
    public function updateOwnPaySignoff(Request $request): JsonResponse
    {
        $data = $request->validate(['allowed' => ['required', 'boolean']]);
        $company = $this->company($request);
        PayrollSettings::save($company, ['own_pay_signoff' => (bool) $data['allowed']] + PayrollSettings::all($company));
        activity('payroll')->performedOn($company)->causedBy($request->user())->withProperties(['own_pay_signoff' => (bool) $data['allowed']])
            ->log($data['allowed'] ? 'Allowed sign-off by staff paid in the run' : 'Blocked sign-off by staff paid in the run');

        return response()->json(['data' => PayrollSettings::all($company->fresh()), 'message' => $data['allowed']
            ? 'Staff paid in a run may now review and approve it. Each such sign-off is recorded in the audit trail.'
            : 'Staff paid in a run can no longer sign it off.']);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pay_day' => ['required', 'integer', 'min:1', 'max:31'],
            'cutoff_day' => ['required', 'integer', 'min:1', 'max:31'],
            'overtime_multiplier_normal' => ['required', 'numeric', 'min:1', 'max:5'],
            'overtime_multiplier_holiday' => ['required', 'numeric', 'min:1', 'max:5'],
            'overtime_monthly_hours' => ['nullable', 'numeric', 'min:1', 'max:400'],
            'advance_max_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'leave_encashment_divisor' => ['required', 'numeric', 'min:1', 'max:31'],
            'variance_threshold' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'payslip_password' => ['nullable', Rule::in(['none', 'national_id'])],
            'own_pay_signoff' => ['nullable', 'boolean'],
            'payroll_gl' => ['nullable', 'array'],
            'payroll_gl.*' => ['nullable', 'string', 'max:30'],
        ]);
        $company = $this->company($request);
        $data['variance_threshold'] = $data['variance_threshold'] ?? PayrollSettings::DEFAULTS['variance_threshold'];
        $data['payslip_password'] = $data['payslip_password'] ?? 'none';
        // Relaxing the own-pay control is an HR/admin decision, never the payroll preparer's.
        $data['own_pay_signoff'] = (bool) ($data['own_pay_signoff'] ?? PayrollSettings::get($company, 'own_pay_signoff'));
        abort_if($data['own_pay_signoff'] !== (bool) PayrollSettings::get($company, 'own_pay_signoff') && ! $request->user()->can('settings.manage'), 403,
            'Only an HR administrator can change who may sign off a run that includes their own pay.');
        PayrollSettings::save($company, $data);
        activity('payroll')->performedOn($company)->causedBy($request->user())->withProperties($data)->log('Payroll settings updated');

        return response()->json(['data' => PayrollSettings::all($company->fresh()), 'message' => 'Payroll settings saved.']);
    }

    // ---------------------------------------------------------------- pay calendar

    public function calendar(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $year = $request->integer('year') ?: now()->year;
        $runs = PayrollRun::where('company_id', $company->id)->where('type', 'regular')->whereYear('period', $year)->get()->keyBy(fn ($r) => $r->period->format('Y-m'));
        $rows = PayrollCalendar::where('company_id', $company->id)->whereYear('period', $year)->orderBy('period')->get()
            ->map(function ($c) use ($runs, $company) {
                $run = $runs->get($c->period->format('Y-m'));

                return [
                    'id' => $c->id, 'period' => $c->period->format('Y-m'), 'month_name' => $c->period->format('F Y'),
                    'cutoff_date' => $c->cutoff_date->toDateString(), 'pay_date' => $c->pay_date->toDateString(),
                    'statutory_due' => $c->period->copy()->addMonth()->day(9)->toDateString(),
                    'working_days' => WorkingDays::between($c->period->copy()->startOfMonth(), $c->period->copy()->endOfMonth(), $company->id),
                    'status' => $run ? $run->status : $c->status, 'run_id' => $run?->id, 'notes' => $c->notes,
                ];
            });

        return response()->json(['data' => $rows, 'year' => $year]);
    }

    public function generateCalendar(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100']]);
        $company = $this->company($request);
        $created = $this->ensureCalendar($company, (int) $data['year']);

        return response()->json(['message' => $created ? "{$created} month(s) added to the {$data['year']} calendar." : "The {$data['year']} calendar is already complete.", 'created' => $created]);
    }

    public static function ensureCalendar($company, int $year): int
    {
        $payDay = (int) PayrollSettings::get($company, 'pay_day');
        $cutoff = (int) PayrollSettings::get($company, 'cutoff_day');
        $created = 0;
        for ($m = 1; $m <= 12; $m++) {
            $period = CarbonImmutable::create($year, $m, 1);
            $pay = $period->day(min($payDay, $period->daysInMonth));
            // Pay before a weekend
            while ($pay->isWeekend()) {
                $pay = $pay->subDay();
            }
            $row = PayrollCalendar::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'period' => $period->toDateString()],
                ['cutoff_date' => $period->day(min($cutoff, $period->daysInMonth))->toDateString(), 'pay_date' => $pay->toDateString(), 'status' => 'scheduled'],
            );
            $created += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    public function updateCalendar(Request $request, PayrollCalendar $calendar): JsonResponse
    {
        $data = $request->validate([
            'cutoff_date' => ['required', 'date'],
            'pay_date' => ['required', 'date', 'after_or_equal:cutoff_date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $calendar->update($data);
        PayrollRun::where('company_id', $calendar->company_id)->where('type', 'regular')->whereDate('period', $calendar->period->toDateString())
            ->whereIn('status', ['draft', 'calculated', 'reviewed'])->update(['pay_date' => $data['pay_date']]);

        return response()->json(['message' => 'Calendar updated.']);
    }
}
