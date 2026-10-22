<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\PayrollComponent;
use Illuminate\Support\Facades\DB;

/** Default Kenya statutory rows and standard pay components. All methods are idempotent. */
class PayrollDefaults
{
    public const EFFECTIVE_FROM = '2025-02-01';

    public static function statutoryRows(): array
    {
        $from = self::EFFECTIVE_FROM;
        $row = fn (array $r) => array_merge([
            'lower_limit' => null, 'upper_limit' => null, 'rate' => null, 'employer_rate' => null,
            'amount' => null, 'cap' => null, 'tax_deductible' => false, 'effective_from' => $from,
            'effective_to' => null, 'notes' => null,
        ], $r);

        return [
            $row(['type' => 'paye_band', 'name' => 'PAYE band 1', 'lower_limit' => 0, 'upper_limit' => 24000, 'rate' => 10, 'effective_from' => '2023-07-01']),
            $row(['type' => 'paye_band', 'name' => 'PAYE band 2', 'lower_limit' => 24000, 'upper_limit' => 32333, 'rate' => 25, 'effective_from' => '2023-07-01']),
            $row(['type' => 'paye_band', 'name' => 'PAYE band 3', 'lower_limit' => 32333, 'upper_limit' => 500000, 'rate' => 30, 'effective_from' => '2023-07-01']),
            $row(['type' => 'paye_band', 'name' => 'PAYE band 4', 'lower_limit' => 500000, 'upper_limit' => 800000, 'rate' => 32.5, 'effective_from' => '2023-07-01']),
            $row(['type' => 'paye_band', 'name' => 'PAYE band 5', 'lower_limit' => 800000, 'upper_limit' => null, 'rate' => 35, 'effective_from' => '2023-07-01']),
            $row(['type' => 'personal_relief', 'name' => 'Personal relief', 'amount' => 2400, 'effective_from' => '2023-07-01']),
            $row(['type' => 'insurance_relief', 'name' => 'Insurance relief', 'rate' => 15, 'cap' => 5000, 'effective_from' => '2023-07-01']),
            $row(['type' => 'nssf', 'name' => 'NSSF Tier I & II', 'lower_limit' => 8000, 'upper_limit' => 72000, 'rate' => 6, 'employer_rate' => 6, 'tax_deductible' => true, 'effective_to' => '2026-01-31', 'notes' => 'NSSF Act 2013 phase-in, Year 3 limits (LEL 8,000 / UEL 72,000).']),
            $row(['type' => 'nssf', 'name' => 'NSSF Tier I & II (Year 4)', 'lower_limit' => 9000, 'upper_limit' => 108000, 'rate' => 6, 'employer_rate' => 6, 'tax_deductible' => true, 'effective_from' => '2026-02-01', 'notes' => 'NSSF Act 2013 phase-in, Year 4 limits (LEL 9,000 / UEL 108,000), 6% employee + 6% employer per tier. Confirm against the official NSSF notice.']),
            $row(['type' => 'shif', 'name' => 'Social Health Insurance Fund', 'rate' => 2.75, 'amount' => 300, 'tax_deductible' => true, 'effective_from' => '2024-10-01', 'notes' => 'Minimum contribution 300.']),
            $row(['type' => 'ahl', 'name' => 'Affordable Housing Levy', 'rate' => 1.5, 'employer_rate' => 1.5, 'tax_deductible' => true, 'effective_from' => '2024-12-27']),
            $row(['type' => 'pension_cap', 'name' => 'Allowable pension contribution', 'amount' => 30000, 'cap' => 30000, 'notes' => 'Monthly cap on tax-deductible pension contributions.', 'tax_deductible' => true, 'effective_from' => '2024-12-27']),
            $row(['type' => 'disability_exemption', 'name' => 'Disability exemption (monthly)', 'amount' => 150000, 'effective_from' => '2023-07-01']),
        ];
    }

    public static function seedStatutory(): void
    {
        foreach (self::statutoryRows() as $r) {
            $exists = DB::table('payroll_statutory_rates')->where('type', $r['type'])->where('name', $r['name'])
                ->where('effective_from', $r['effective_from'])->exists();
            if (! $exists) {
                DB::table('payroll_statutory_rates')->insert($r + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** code, name, category, calculation, amount, rate, role, taxable, pensionable, recurring, gl */
    public static function components(): array
    {
        $c = fn ($code, $name, $cat, $role = null, $taxable = true, $recurring = true, $gl = null, $calc = 'fixed', $amount = null, $rate = null, $pensionable = false, $sort = 100) => compact('code', 'name', 'cat', 'role', 'taxable', 'recurring', 'gl', 'calc', 'amount', 'rate', 'pensionable', 'sort');

        return [
            $c('HOUSE', 'House allowance', 'earning', null, true, true, '6110', 'percent_of_basic', null, 15, false, 10),
            $c('TRANSPORT', 'Transport allowance', 'earning', null, true, true, '6120', 'fixed', 5000, null, false, 11),
            $c('COMMUTER', 'Commuter allowance', 'earning', null, true, true, '6120', 'fixed', 3000, null, false, 12),
            $c('AIRTIME', 'Airtime allowance', 'earning', null, true, true, '6130', 'fixed', 2000, null, false, 13),
            $c('OVERTIME', 'Overtime', 'earning', 'overtime', true, false, '6140', 'fixed', null, null, false, 14),
            $c('BONUS', 'Bonus', 'earning', 'bonus', true, false, '6150', 'fixed', null, null, false, 15),
            $c('COMMISSION', 'Commission', 'earning', null, true, false, '6160', 'fixed', null, null, false, 16),
            $c('ARREARS', 'Salary arrears', 'earning', 'arrears', true, false, '6100', 'fixed', null, null, true, 17),
            $c('LEAVE_ENC', 'Leave encashment', 'earning', 'leave_encashment', true, false, '6100', 'fixed', null, null, false, 18),
            $c('NOTICE_PAY', 'Notice pay', 'earning', 'notice_pay', true, false, '6100', 'fixed', null, null, false, 19),
            $c('SACCO', 'SACCO contribution', 'deduction', 'sacco', false, true, '2240', 'fixed', 2000, null, false, 30),
            $c('INSURANCE', 'Insurance premium', 'deduction', 'insurance_premium', false, true, '2250', 'fixed', 1500, null, false, 31),
            $c('UNION', 'Union dues', 'deduction', null, false, true, '2260', 'percent_of_basic', null, 2, false, 32),
            $c('HELB', 'HELB repayment', 'deduction', 'helb', false, true, '2230', 'fixed', 3000, null, false, 33),
            $c('LOAN', 'Loan repayment', 'deduction', 'loan', false, true, '1310', 'fixed', null, null, false, 34),
            $c('ADVANCE', 'Salary advance recovery', 'deduction', 'advance', false, false, '1320', 'fixed', null, null, false, 35),
            $c('LEAVE_REC', 'Leave over-taken recovery', 'deduction', 'leave_recovery', false, false, '6100', 'fixed', null, null, false, 37),
            $c('PEN_EE', 'Pension (employee)', 'deduction', 'pension_employee', false, true, '2270', 'percent_of_basic', null, 5, false, 36),
            $c('PEN_ER', 'Pension (employer)', 'employer_contribution', 'pension_employer', false, true, '6210', 'percent_of_basic', null, 5, false, 40),
        ];
    }

    public static function ensureComponents(int $companyId): void
    {
        foreach (self::components() as $c) {
            PayrollComponent::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $c['code']],
                [
                    'name' => $c['name'], 'category' => $c['cat'], 'calculation' => $c['calc'],
                    'default_amount' => $c['amount'], 'default_rate' => $c['rate'], 'role' => $c['role'],
                    'taxable' => $c['taxable'], 'pensionable' => $c['pensionable'], 'recurring' => $c['recurring'],
                    'gl_account' => $c['gl'], 'active' => true, 'sort_order' => $c['sort'],
                ],
            );
        }
    }
}
