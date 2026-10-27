<?php

namespace App\Modules\Payroll\Services\Calculation;

/**
 * Pure Kenyan payroll calculation for one employee and one period.
 *
 *   gross = prorated basic − unpaid / half-pay leave + earnings + overtime
 *   → NSSF (Tier I / II), SHIF, AHL, pension
 *   → taxable pay = gross − non-taxable earnings − allowable deductions (− disability exemption)
 *   → PAYE = tax(taxable) − personal relief − insurance relief (floor 0)
 *   → other deductions (loans, advances, SACCO, HELB …) → net
 *   → + reimbursements (non-taxable, outside gross and statutory) → net pay
 *
 * Returns a flat array of totals plus 'items' (payslip lines) and 'flags' (exceptions).
 */
final class PayrollCalculator
{
    public function __construct(private FormulaEvaluator $formulas = new FormulaEvaluator) {}

    public function calculate(CalculationInput $in, StatutoryRates $rates): array
    {
        $items = [];
        $basic = max(0.0, $in->basicSalary);

        // ---- Days & proration ------------------------------------------------------------
        // One proration rule for joiners, leavers, unpaid and half-pay leave: WORKING days (Mon–Fri less holidays).
        //   employment factor = working days employed in the period / working days in the period
        //   pay factor        = (working days employed − unpaid days − ½ × half-pay days) / working days in the period
        // Basic pay, recurring fixed earnings and percent-of-basic components all follow the same pay factor.
        $daysInPeriod = $in->periodStart->diffInDays($in->periodEnd) + 1;
        $from = $in->employmentStart && $in->employmentStart->gt($in->periodStart) ? $in->employmentStart : $in->periodStart;
        $to = $in->employmentEnd && $in->employmentEnd->lt($in->periodEnd) ? $in->employmentEnd : $in->periodEnd;
        $workingDays = $in->workingDays > 0 ? $in->workingDays : 22;
        $wholePeriod = $from->equalTo($in->periodStart) && $to->equalTo($in->periodEnd);
        $daysWorked = (float) match (true) {
            ! $in->prorate || ($wholePeriod && $in->employedWorkingDays === null) => $workingDays,
            $to->lt($from) => 0.0,
            $in->employedWorkingDays !== null => max(0.0, min($workingDays, $in->employedWorkingDays)),
            default => min($workingDays, (float) self::weekdays($from, $to)),
        };
        $factor = $daysWorked / $workingDays;

        $unpaidDays = max(0.0, $in->input('unpaid_leave_days'));
        $halfPayDays = max(0.0, $in->input('half_pay_leave_days'));
        $otNormal = max(0.0, $in->input('overtime_hours_normal'));
        $otHoliday = max(0.0, $in->input('overtime_hours_holiday'));
        $lostDays = min($daysWorked, $unpaidDays + 0.5 * $halfPayDays);
        $payFactor = max(0.0, ($daysWorked - $lostDays) / $workingDays);

        $basicPay = 0.0;
        $proratedBasic = 0.0;
        $overtimePay = 0.0;
        if ($in->includeBasic) {
            $proratedBasic = $this->money($basic * $factor);
            $items[] = $this->item('BASIC', $factor < 1 ? sprintf('Basic salary (%s/%s working days)', $this->num($daysWorked), $this->num($workingDays)) : 'Basic salary',
                'earning', $proratedBasic, ['source' => 'basic', 'sort_order' => 1, 'quantity' => $factor < 1 ? $daysWorked : null]);

            $dailyRate = $basic / $workingDays;
            $unpaid = $this->money(min($proratedBasic, $dailyRate * $unpaidDays));
            if ($unpaid > 0) {
                $items[] = $this->item('UNPAID', sprintf('Unpaid leave (%s days)', $this->num($unpaidDays)), 'earning', -$unpaid, ['source' => 'leave', 'sort_order' => 2, 'quantity' => $unpaidDays]);
            }
            $halfPay = $this->money(min($proratedBasic - $unpaid, $dailyRate * $halfPayDays * 0.5));
            if ($halfPay > 0) {
                $items[] = $this->item('HALFPAY', sprintf('Half-pay leave (%s days at 50%%)', $this->num($halfPayDays)), 'earning', -$halfPay, ['source' => 'leave', 'sort_order' => 3, 'quantity' => $halfPayDays]);
            }
            $basicPay = $this->money($proratedBasic - $unpaid - $halfPay);

            // Overtime: hourly rate = basic / monthly hours
            $hourly = $basic / ($in->monthlyHours > 0 ? $in->monthlyHours : 225);
            $otComponent = $this->roleComponent($in, 'overtime');
            if ($otNormal > 0) {
                $amt = $this->money($otNormal * $hourly * $in->overtimeMultiplierNormal);
                $overtimePay += $amt;
                $items[] = $this->item($otComponent['code'] ?? 'OT', sprintf('Overtime %s hrs × %s', $this->num($otNormal), $this->num($in->overtimeMultiplierNormal)), 'earning', $amt,
                    ['source' => 'overtime', 'sort_order' => 40, 'quantity' => $otNormal, 'taxable' => $otComponent['taxable'] ?? true, 'gl_account' => $otComponent['gl_account'] ?? null, 'component_id' => $otComponent['component_id'] ?? null]);
            }
            if ($otHoliday > 0) {
                $amt = $this->money($otHoliday * $hourly * $in->overtimeMultiplierHoliday);
                $overtimePay += $amt;
                $items[] = $this->item(($otComponent['code'] ?? 'OT').'_HOL', sprintf('Overtime (rest day / holiday) %s hrs × %s', $this->num($otHoliday), $this->num($in->overtimeMultiplierHoliday)), 'earning', $amt,
                    ['source' => 'overtime', 'sort_order' => 41, 'quantity' => $otHoliday, 'taxable' => $otComponent['taxable'] ?? true, 'gl_account' => $otComponent['gl_account'] ?? null, 'component_id' => $otComponent['component_id'] ?? null]);
            }
        }

        $vars = [
            'basic' => $basic, 'basic_pay' => $basicPay, 'days_worked' => $daysWorked, 'working_days' => $workingDays,
            'days_in_period' => $daysInPeriod, 'overtime_hours' => $otNormal + $otHoliday, 'gross' => 0,
        ];

        // ---- Earnings ---------------------------------------------------------------------
        $earnings = array_values(array_filter($in->components, fn ($c) => ($c['category'] ?? '') === 'earning' && ! ($c['meta_only'] ?? false)));
        $nonTaxable = 0.0;
        $pensionable = $basicPay;
        $gross = $basicPay + $overtimePay;
        $deferred = [];
        foreach ($earnings as $c) {
            if (in_array($c['calculation'] ?? 'fixed', ['percent_of_gross', 'formula'], true)) {
                $deferred[] = $c;

                continue;
            }
            $amt = $this->componentAmount($c, $basicPay, 0, $vars, $payFactor);
            $gross += $this->addEarning($items, $c, $amt, $nonTaxable, $pensionable);
        }
        $vars['gross'] = $gross;
        foreach ($deferred as $c) {
            $amt = $this->componentAmount($c, $basicPay, $vars['gross'], $vars, $payFactor);
            $gross += $this->addEarning($items, $c, $amt, $nonTaxable, $pensionable);
        }
        $gross = $this->money($gross);
        $vars['gross'] = $gross;

        // ---- Statutory --------------------------------------------------------------------
        $cumGross = $gross + $in->prior('gross');

        $tier1Total = $this->money(min($cumGross, $rates->nssfLowerLimit) * $rates->nssfRate / 100);
        $tier2Total = $this->money(max(0, min($cumGross, $rates->nssfUpperLimit) - $rates->nssfLowerLimit) * $rates->nssfRate / 100);
        $nssfTier1 = max(0.0, $this->money($tier1Total - $in->prior('nssf_tier1')));
        $nssfTier2 = max(0.0, $this->money($tier2Total - $in->prior('nssf_tier2')));
        if ($gross <= 0) {
            $nssfTier1 = $nssfTier2 = 0.0;
        }
        $nssfEmployee = $this->money($nssfTier1 + $nssfTier2);
        $nssfEmployer = $rates->nssfRate > 0 ? $this->money($nssfEmployee * $rates->nssfEmployerRate / $rates->nssfRate) : 0.0;

        $shif = 0.0;
        if ($gross > 0) {
            $shifTotal = max($this->money($cumGross * $rates->shifRate / 100), $rates->shifMinimum);
            $shif = max(0.0, $this->money($shifTotal - $in->prior('shif')));
        }
        $ahlEmployee = $gross > 0 ? $this->money($gross * $rates->ahlRate / 100) : 0.0;
        $ahlEmployer = $gross > 0 ? $this->money($gross * $rates->ahlEmployerRate / 100) : 0.0;

        // Pension: profile % of pensionable pay plus components flagged as pension
        $pensionable = $this->money($pensionable);
        $pensionEmployee = $this->money($pensionable * $in->pensionEmployeePercent / 100);
        $pensionEmployer = $this->money($pensionable * $in->pensionEmployerPercent / 100);
        $penEeComp = $this->roleComponent($in, 'pension_employee');
        $penErComp = $this->roleComponent($in, 'pension_employer');

        // Deductions & employer contributions from components (evaluated on final gross)
        $deductionLines = [];
        $employerLines = [];
        $insurancePremiums = 0.0;
        $helb = 0.0;
        foreach ($in->components as $c) {
            $cat = $c['category'] ?? '';
            if (! in_array($cat, ['deduction', 'employer_contribution'], true) || ($c['meta_only'] ?? false)) {
                continue;
            }
            $amt = $this->money($this->componentAmount($c, $basicPay, $gross, $vars, $payFactor, prorateFixed: false));
            if ($amt == 0.0) {
                continue;
            }
            $role = $c['role'] ?? null;
            if ($cat === 'deduction' && $role === 'pension_employee') {
                $pensionEmployee += $amt;

                continue;
            }
            if ($cat === 'employer_contribution' && $role === 'pension_employer') {
                $pensionEmployer += $amt;

                continue;
            }
            if ($cat === 'deduction') {
                if ($role === 'insurance_premium') {
                    $insurancePremiums += $amt;
                }
                if ($role === 'helb') {
                    $helb += $amt;
                }
                $deductionLines[] = [$c, $amt];
            } else {
                $employerLines[] = [$c, $amt];
            }
        }
        $pensionEmployee = $this->money($pensionEmployee);
        $pensionEmployer = $this->money($pensionEmployer);

        // ---- Taxable pay & PAYE -------------------------------------------------------------
        $pensionAllowable = $this->money(min($pensionEmployee, max(0, $rates->pensionCap - $in->prior('pension_allowable'))));
        $allowable = $this->money(
            ($rates->nssfDeductible ? $nssfEmployee : 0)
            + ($rates->shifDeductible ? $shif : 0)
            + ($rates->ahlDeductible ? $ahlEmployee : 0)
            + $pensionAllowable
        );
        $taxable = max(0.0, $gross - $nonTaxable - $allowable);
        $exemption = 0.0;
        if ($in->disabilityExempt) {
            $exemption = min($taxable, max(0, $rates->disabilityExemption - $in->prior('disability_exemption')));
            $taxable -= $exemption;
        }
        $taxable = $this->money($taxable);

        $cumTaxable = $taxable + $in->prior('taxable_pay');
        $taxTotal = $rates->tax($cumTaxable);
        $insuranceReliefTotal = $this->money(min(($insurancePremiums + $in->prior('insurance_premiums')) * $rates->insuranceReliefRate / 100, $rates->insuranceReliefCap));
        $personalGiven = min($taxTotal, $rates->personalRelief);
        $insuranceGiven = min($taxTotal - $personalGiven, $insuranceReliefTotal);
        $reliefTotal = $personalGiven + $insuranceGiven;

        $taxCharged = $this->money($taxTotal - $in->prior('tax_charged'));
        $priorReliefs = $in->prior('reliefs');
        $personalRelief = $this->money(max(0, $personalGiven - min($priorReliefs, $personalGiven)));
        $insuranceRelief = $this->money(max(0, $reliefTotal - $priorReliefs - $personalRelief));
        $paye = max(0.0, $this->money(($taxTotal - $reliefTotal) - $in->prior('paye')));

        // ---- Lines --------------------------------------------------------------------------
        $items[] = $this->item('PAYE', 'PAYE', 'statutory', $paye, ['source' => 'statutory', 'sort_order' => 200]);
        if ($nssfTier1 > 0) {
            $items[] = $this->item('NSSF_T1', 'NSSF Tier I', 'statutory', $nssfTier1, ['source' => 'statutory', 'sort_order' => 201]);
        }
        if ($nssfTier2 > 0) {
            $items[] = $this->item('NSSF_T2', 'NSSF Tier II', 'statutory', $nssfTier2, ['source' => 'statutory', 'sort_order' => 202]);
        }
        if ($shif > 0) {
            $items[] = $this->item('SHIF', 'SHIF', 'statutory', $shif, ['source' => 'statutory', 'sort_order' => 203]);
        }
        if ($ahlEmployee > 0) {
            $items[] = $this->item('AHL', 'Affordable Housing Levy', 'statutory', $ahlEmployee, ['source' => 'statutory', 'sort_order' => 204]);
        }
        if ($pensionEmployee > 0) {
            $items[] = $this->item($penEeComp['code'] ?? 'PEN_EE', $penEeComp['name'] ?? 'Pension (employee)', 'deduction', $pensionEmployee,
                ['source' => 'pension', 'sort_order' => 300, 'gl_account' => $penEeComp['gl_account'] ?? null, 'component_id' => $penEeComp['component_id'] ?? null]);
        }

        $otherDeductions = 0.0;
        $sort = 310;
        foreach ($deductionLines as [$c, $amt]) {
            if (($c['role'] ?? null) !== 'helb') {
                $otherDeductions += $amt;
            }
            $items[] = $this->item($c['code'], $c['name'], 'deduction', $amt, [
                'source' => $c['source'] ?? 'component', 'source_id' => $c['source_id'] ?? null, 'sort_order' => $sort++,
                'gl_account' => $c['gl_account'] ?? null, 'component_id' => $c['component_id'] ?? null,
            ]);
        }
        foreach ($in->deductions as $d) {
            $amt = $this->money((float) $d['amount']);
            if ($amt <= 0) {
                continue;
            }
            if (($d['role'] ?? null) === 'helb') {
                $helb += $amt;
            } else {
                $otherDeductions += $amt;
            }
            $items[] = $this->item($d['code'], $d['name'], 'deduction', $amt, [
                'source' => $d['source'] ?? 'loan', 'source_id' => $d['source_id'] ?? null, 'sort_order' => $sort++,
                'gl_account' => $d['gl_account'] ?? null, 'component_id' => $d['component_id'] ?? null,
            ]);
        }
        $otherDeductions = $this->money($otherDeductions);
        $helb = $this->money($helb);

        $employerOther = 0.0;
        if ($nssfEmployer > 0) {
            $items[] = $this->item('NSSF_ER', 'NSSF (employer)', 'employer', $nssfEmployer, ['source' => 'statutory', 'sort_order' => 400]);
        }
        if ($ahlEmployer > 0) {
            $items[] = $this->item('AHL_ER', 'Housing Levy (employer)', 'employer', $ahlEmployer, ['source' => 'statutory', 'sort_order' => 401]);
        }
        if ($pensionEmployer > 0) {
            $items[] = $this->item($penErComp['code'] ?? 'PEN_ER', $penErComp['name'] ?? 'Pension (employer)', 'employer', $pensionEmployer,
                ['source' => 'pension', 'sort_order' => 402, 'gl_account' => $penErComp['gl_account'] ?? null, 'component_id' => $penErComp['component_id'] ?? null]);
        }
        foreach ($employerLines as [$c, $amt]) {
            $employerOther += $amt;
            $items[] = $this->item($c['code'], $c['name'], 'employer', $amt, [
                'source' => 'component', 'sort_order' => 410, 'gl_account' => $c['gl_account'] ?? null, 'component_id' => $c['component_id'] ?? null,
            ]);
        }

        // Informational tax lines
        $items[] = $this->item('TAXABLE', 'Taxable pay', 'tax', $taxable, ['sort_order' => 500]);
        $items[] = $this->item('TAX_CHARGED', 'Tax charged', 'tax', $taxCharged, ['sort_order' => 501]);
        if ($personalRelief > 0) {
            $items[] = $this->item('RELIEF_PERSONAL', 'Personal relief', 'relief', $personalRelief, ['sort_order' => 502]);
        }
        if ($insuranceRelief > 0) {
            $items[] = $this->item('RELIEF_INSURANCE', 'Insurance relief', 'relief', $insuranceRelief, ['sort_order' => 503]);
        }
        if ($exemption > 0) {
            $items[] = $this->item('DISABILITY_EXEMPT', 'Disability exemption', 'relief', $this->money($exemption), ['sort_order' => 504]);
        }

        $statutory = $this->money($paye + $nssfEmployee + $shif + $ahlEmployee);
        $totalDeductions = $this->money($statutory + $pensionEmployee + $helb + $otherDeductions);
        $employerCost = $this->money($gross + $nssfEmployer + $ahlEmployer + $pensionEmployer + $employerOther);

        // Reimbursements (approved expense claims paid through payroll) are not pay: no tax, no statutory
        // contributions, not part of gross. They are added straight to net pay and shown on the payslip.
        $reimbursements = $this->money(max(0.0, $in->input('reimbursements')));
        if ($reimbursements > 0) {
            $items[] = $this->item('REIMB', 'Expense reimbursements', 'reimbursement', $reimbursements,
                ['taxable' => false, 'source' => 'reimbursement', 'sort_order' => 90]);
        }
        $netBeforeReimbursements = $this->money($gross - $totalDeductions);
        $net = $this->money($netBeforeReimbursements + $reimbursements);

        $flags = [];
        if ($net < 0) {
            $flags[] = ['code' => 'negative_net', 'severity' => 'error', 'message' => 'Net pay is negative.'];
        } elseif ($gross > 0 && $netBeforeReimbursements < $gross / 3) {
            $flags[] = ['code' => 'below_one_third', 'severity' => 'warning', 'message' => 'Net pay is below one third of gross pay.'];
        }

        usort($items, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return [
            'basic_salary' => $this->money($basic),
            'basic_pay' => $basicPay,
            'days_in_period' => $daysInPeriod,
            'days_worked' => $daysWorked,
            'working_days' => $workingDays,
            'unpaid_days' => $unpaidDays,
            'half_pay_days' => $halfPayDays,
            'overtime_hours' => $otNormal + $otHoliday,
            'overtime_pay' => $this->money($overtimePay),
            'gross_pay' => $gross,
            'non_taxable_pay' => $this->money($nonTaxable),
            'pensionable_pay' => $pensionable,
            'nssf_tier1' => $nssfTier1,
            'nssf_tier2' => $nssfTier2,
            'nssf_employee' => $nssfEmployee,
            'nssf_employer' => $nssfEmployer,
            'shif' => $shif,
            'ahl_employee' => $ahlEmployee,
            'ahl_employer' => $ahlEmployer,
            'pension_employee' => $pensionEmployee,
            'pension_employer' => $pensionEmployer,
            'pension_allowable' => $pensionAllowable,
            'insurance_premiums' => $this->money($insurancePremiums),
            'allowable_deductions' => $allowable,
            'disability_exemption' => $this->money($exemption),
            'taxable_pay' => $taxable,
            'tax_charged' => $taxCharged,
            'personal_relief' => $personalRelief,
            'insurance_relief' => $insuranceRelief,
            'paye' => $paye,
            'helb' => $helb,
            'other_deductions' => $otherDeductions,
            'reimbursements' => $reimbursements,
            'total_deductions' => $totalDeductions,
            'net_pay' => $net,
            'employer_cost' => $employerCost,
            'flags' => $flags,
            'items' => $items,
        ];
    }

    /** percent_of_basic is taken on basic pay after proration and leave; recurring fixed earnings scale by the pay factor. */
    private function componentAmount(array $c, float $basicPay, float $gross, array $vars, float $factor, bool $prorateFixed = true): float
    {
        $calc = $c['calculation'] ?? 'fixed';
        $rate = (float) ($c['rate'] ?? 0);
        $amount = match ($calc) {
            'percent_of_basic' => $basicPay * $rate / 100,
            'percent_of_gross' => $gross * $rate / 100,
            'formula' => $this->formulas->evaluate((string) ($c['formula'] ?? '0'), $vars),
            default => (float) ($c['amount'] ?? 0),
        };
        // Recurring fixed earnings follow the same pay factor as basic (joiners, leavers, unpaid / half-pay leave).
        $isRecurring = ! in_array($c['source'] ?? 'component', ['one_off', 'leave_settlement'], true) && ($c['recurring'] ?? true);
        if ($calc === 'fixed' && $prorateFixed && $isRecurring && $factor < 1) {
            $amount *= $factor;
        }

        return $this->money($amount);
    }

    /** Mon–Fri between two dates (fallback when the caller did not supply holiday-aware working days). */
    private static function weekdays(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): int
    {
        $n = 0;
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $n += $d->isWeekend() ? 0 : 1;
        }

        return $n;
    }

    private function addEarning(array &$items, array $c, float $amount, float &$nonTaxable, float &$pensionable): float
    {
        if ($amount == 0.0) {
            return 0.0;
        }
        if (! ($c['taxable'] ?? true)) {
            $nonTaxable += $amount;
        }
        if ($c['pensionable'] ?? false) {
            $pensionable += $amount;
        }
        $items[] = $this->item($c['code'], $c['name'], 'earning', $amount, [
            'taxable' => (bool) ($c['taxable'] ?? true), 'source' => $c['source'] ?? 'component', 'source_id' => $c['source_id'] ?? null,
            'gl_account' => $c['gl_account'] ?? null, 'component_id' => $c['component_id'] ?? null,
            'sort_order' => in_array($c['source'] ?? '', ['one_off', 'leave_settlement'], true) ? 60 : 20,
        ]);

        return $amount;
    }

    private function roleComponent(CalculationInput $in, string $role): ?array
    {
        foreach ($in->components as $c) {
            if (($c['role'] ?? null) === $role && ($c['meta_only'] ?? false)) {
                return $c;
            }
        }

        return null;
    }

    private function item(string $code, string $name, string $category, float $amount, array $extra = []): array
    {
        return array_merge([
            'code' => $code, 'name' => $name, 'category' => $category, 'amount' => $this->money($amount),
            'quantity' => null, 'taxable' => true, 'source' => null, 'source_id' => null,
            'gl_account' => null, 'component_id' => null, 'sort_order' => 100,
        ], $extra);
    }

    private function money(float $v): float
    {
        return round($v + 0.0, 2);
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
