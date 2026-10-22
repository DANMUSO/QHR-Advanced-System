<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PayslipService
{
    public static function mask(?string $value, int $visible = 4): ?string
    {
        if (blank($value)) {
            return null;
        }
        $value = preg_replace('/\s+/', '', $value);

        return strlen($value) <= $visible ? $value : str_repeat('•', max(3, strlen($value) - $visible)).substr($value, -$visible);
    }

    /** Full payslip data for one run line (masked payment details, YTD figures). */
    public function data(PayrollRunEmployee $line): array
    {
        $line->loadMissing(['run', 'items', 'employee.position', 'employee.department', 'employee.branch']);
        $run = $line->run;
        $e = $line->employee;
        $company = Company::find($line->company_id);
        $items = $line->items;
        $group = fn (string ...$cats) => $items->whereIn('category', $cats)->values()
            ->map(fn ($i) => ['code' => $i->code, 'name' => $i->name, 'amount' => (float) $i->amount, 'quantity' => $i->quantity])->all();

        $ytdLines = PayrollRunEmployee::withoutGlobalScopes()->where('employee_id', $line->employee_id)
            ->whereHas('run', fn ($q) => $q->whereYear('period', $run->period->year)
                ->whereDate('period', '<=', $run->period->toDateString())
                ->where(fn ($w) => $w->whereIn('status', ['approved', 'paid'])->orWhere('id', $run->id)))
            ->get();

        $details = $line->payment_details ?? [];
        $paymentLabel = match ($line->payment_method) {
            'bank' => trim(($details['bank_name'] ?? 'Bank').' '.($details['bank_branch'] ?? '')).' · '.(self::mask($details['account_number'] ?? null) ?? '—'),
            'mpesa' => 'M-Pesa · '.(self::mask($details['mpesa_phone'] ?? null, 3) ?? '—'),
            default => 'Cash',
        };

        return [
            'id' => $line->id,
            'run' => ['id' => $run->id, 'title' => $run->title, 'period' => $run->period->format('Y-m'), 'period_label' => $run->period->format('F Y'),
                'pay_date' => $run->pay_date?->toDateString(), 'status' => $run->status, 'type' => $run->type, 'off_cycle_reason' => $run->off_cycle_reason],
            'company' => ['name' => $company?->name, 'address' => $company?->address, 'tax_pin' => $company?->tax_pin, 'logo_url' => $company?->logo_url,
                'logo_path' => $company?->logo_path, 'currency' => $company?->setting('currency', 'KES') ?? 'KES'],
            'employee' => [
                'id' => $e?->id, 'name' => $e?->full_name, 'employee_number' => $e?->employee_number, 'position' => $e?->position?->title,
                'department' => $e?->department?->name, 'branch' => $e?->branch?->name, 'kra_pin' => $e?->kra_pin,
                'nssf_number' => $e?->nssf_number, 'shif_number' => $e?->shif_number, 'national_id' => self::mask($e?->national_id, 3),
                'date_of_joining' => $e?->date_of_joining?->toDateString(),
            ],
            'payment' => ['method' => $line->payment_method, 'label' => $paymentLabel],
            'days' => ['in_period' => $line->days_in_period, 'worked' => $line->days_worked, 'working_days' => $line->working_days,
                'unpaid' => $line->unpaid_days, 'half_pay' => $line->half_pay_days, 'overtime_hours' => $line->overtime_hours],
            'earnings' => $group('earning'),
            'statutory' => $group('statutory'),
            'deductions' => $group('deduction'),
            'employer' => $group('employer'),
            'reimbursements' => $group('reimbursement'),
            'tax' => $group('tax', 'relief'),
            'totals' => collect(PayrollRunEmployee::MONEY)->mapWithKeys(fn ($k) => [$k => (float) $line->{$k}])->all(),
            'ytd' => [
                'gross_pay' => round($ytdLines->sum('gross_pay'), 2),
                'taxable_pay' => round($ytdLines->sum('taxable_pay'), 2),
                'paye' => round($ytdLines->sum('paye'), 2),
                'nssf' => round($ytdLines->sum('nssf_employee'), 2),
                'shif' => round($ytdLines->sum('shif'), 2),
                'ahl' => round($ytdLines->sum('ahl_employee'), 2),
                'pension' => round($ytdLines->sum('pension_employee'), 2),
                'net_pay' => round($ytdLines->sum('net_pay'), 2),
            ],
            'flags' => $line->flags ?? [],
        ];
    }

    public function filename(PayrollRunEmployee $line): string
    {
        $line->loadMissing(['run', 'employee']);

        return 'Payslip-'.($line->employee?->employee_number ?: $line->employee_id).'-'.$line->run->period->format('Y-m').($line->run->type === 'off_cycle' ? '-'.$line->run->id : '').'.pdf';
    }

    public function pdf(PayrollRunEmployee $line): \Barryvdh\DomPDF\PDF
    {
        $data = $this->data($line);
        $data['logo'] = $this->logoDataUri($data['company']['logo_path']);

        return Pdf::loadView('payroll::payslip', ['p' => $data])->setPaper('a4');
    }

    /**
     * Payslip PDF bytes for email. When the company setting payslip_password = national_id, the PDF is encrypted
     * with dompdf's CPDF backend (RC4 128-bit) and opens with the employee's national ID number. Employees without
     * an ID number get an unprotected PDF (returned flag false) rather than one they cannot open.
     *
     * @return array{0: string, 1: bool} [pdf bytes, password protected]
     */
    public function emailPdf(PayrollRunEmployee $line): array
    {
        $line->loadMissing(['employee']);
        $company = Company::find($line->company_id);
        $pdf = $this->pdf($line);
        $password = $company && \App\Modules\Payroll\Support\PayrollSettings::get($company, 'payslip_password') === 'national_id'
            ? preg_replace('/\s+/', '', (string) $line->employee?->national_id) : '';

        if ($password === '') {
            return [$pdf->output(), false];
        }
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        if (! $canvas instanceof \Dompdf\Adapter\CPDF) {
            return [$dompdf->output(), false]; // only the CPDF backend supports encryption
        }
        // Owner password is random so recipients cannot lift the restrictions; printing stays allowed.
        $canvas->get_cpdf()->setEncryption($password, bin2hex(random_bytes(12)), ['print']);

        return [$dompdf->output(), true];
    }

    /** P9A tax deduction card: one row per month of the year from approved / paid runs. */
    public function p9a(Employee $employee, int $year): array
    {
        $lines = PayrollRunEmployee::withoutGlobalScopes()->with('run')->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereYear('period', $year)->whereIn('status', ['approved', 'paid']))->get()
            ->groupBy(fn ($l) => (int) $l->run->period->month);

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $g = $lines->get($m, collect());
            $basic = round($g->sum('basic_pay'), 2);
            $gross = round($g->sum('gross_pay'), 2);
            $rows[] = [
                'month' => date('F', mktime(0, 0, 0, $m, 1)),
                'basic' => $basic,
                'benefits' => round($gross - $basic - $g->sum('non_taxable_pay'), 2),
                'non_taxable' => round($g->sum('non_taxable_pay'), 2),
                'gross' => $gross,
                'retirement' => round($g->sum('nssf_employee') + $g->sum('pension_employee'), 2),
                'shif' => round($g->sum('shif'), 2),
                'ahl' => round($g->sum('ahl_employee'), 2),
                'allowable' => round($g->sum('allowable_deductions'), 2),
                'chargeable' => round($g->sum('taxable_pay'), 2),
                'tax_charged' => round($g->sum('tax_charged'), 2),
                'personal_relief' => round($g->sum('personal_relief'), 2),
                'insurance_relief' => round($g->sum('insurance_relief'), 2),
                'paye' => round($g->sum('paye'), 2),
            ];
        }
        $totals = [];
        foreach (array_keys($rows[0]) as $k) {
            if ($k !== 'month') {
                $totals[$k] = round(array_sum(array_column($rows, $k)), 2);
            }
        }
        $company = Company::find($employee->company_id);

        return [
            'year' => $year,
            'employer' => ['name' => $company?->name, 'pin' => $company?->tax_pin, 'logo_path' => $company?->logo_path],
            'employee' => ['name' => $employee->full_name, 'employee_number' => $employee->employee_number, 'kra_pin' => $employee->kra_pin,
                'national_id' => $employee->national_id],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    public function p9aPdf(Employee $employee, int $year): \Barryvdh\DomPDF\PDF
    {
        $data = $this->p9a($employee, $year);
        $data['logo'] = $this->logoDataUri($data['employer']['logo_path']);

        return Pdf::loadView('payroll::p9a', ['d' => $data])->setPaper('a4', 'landscape');
    }

    private function logoDataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';
        if (! str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }
}
