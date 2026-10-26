<?php

namespace App\Modules\Payroll\Support;

use App\Models\Company;

/** Company payroll settings stored in companies.settings (with defaults). */
class PayrollSettings
{
    public const DEFAULTS = [
        'pay_day' => 28,
        'cutoff_day' => 20,
        'overtime_multiplier_normal' => 1.5,
        'overtime_multiplier_holiday' => 2.0,
        'overtime_monthly_hours' => null,      // null = hours_per_day × working days (fallback 225)
        'advance_max_percent' => 50,
        'leave_encashment_divisor' => 22,
        'variance_threshold' => 10,             // % change vs the previous run that flags an employee line
        'own_pay_signoff' => false,           // allow a reviewer/approver who is paid in the run to sign it off (logged); for small companies
        'payslip_password' => 'none',           // none | national_id: emailed payslip PDFs are opened with the employee's ID number
        'payroll_gl' => [
            'salaries_expense' => '6100',
            'employer_nssf_expense' => '6220',
            'employer_ahl_expense' => '6230',
            'paye_payable' => '2210',
            'nssf_payable' => '2220',
            'shif_payable' => '2225',
            'ahl_payable' => '2235',
            'helb_payable' => '2230',
            'net_pay_payable' => '2100',
            'other_deductions' => '2290',
            'reimbursements' => '2240',          // expense claims payable, cleared when reimbursed through payroll
        ],
    ];

    public static function get(Company $company, string $key): mixed
    {
        $value = $company->setting($key, null);
        if ($key === 'payroll_gl') {
            return array_replace(self::DEFAULTS['payroll_gl'], is_array($value) ? array_filter($value) : []);
        }

        return $value ?? self::DEFAULTS[$key] ?? null;
    }

    public static function all(Company $company): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $out[$k] = self::get($company, $k);
        }
        $out['hours_per_day'] = (float) $company->setting('hours_per_day', 8);
        $out['currency'] = $company->setting('currency', 'KES');

        return $out;
    }

    public static function save(Company $company, array $values): void
    {
        $settings = $company->settings ?? [];
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $settings[$k] = $v;
            }
        }
        $company->settings = $settings;
        $company->save();
    }
}
