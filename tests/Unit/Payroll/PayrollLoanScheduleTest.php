<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\Calculation\LoanSchedule;
use PHPUnit\Framework\TestCase;

class PayrollLoanScheduleTest extends TestCase
{
    public function test_flat_interest_is_included_in_total_and_balance(): void
    {
        $s = LoanSchedule::build(120000, 'flat', 10, 12, '2026-10-01');
        $this->assertSame(12000.0, $s['total_interest']);         // 120,000 x 10% x 12/12
        $this->assertSame(132000.0, $s['total_repayable']);
        $this->assertCount(12, $s['rows']);
        $first = $s['rows'][0];
        $this->assertSame(10000.0, $first['principal']);
        $this->assertSame(1000.0, $first['interest']);
        $this->assertSame(11000.0, $first['amount']);
        $this->assertSame(121000.0, $first['balance_after']);     // total repayable - first instalment
        $this->assertSame('2026-10-01', $first['period']);
        $last = $s['rows'][11];
        $this->assertSame(0.0, $last['balance_after']);
        $this->assertEqualsWithDelta(132000, array_sum(array_column($s['rows'], 'amount')), 0.001);
    }

    public function test_reducing_balance_interest_declines_and_reconciles(): void
    {
        $s = LoanSchedule::build(100000, 'reducing', 12, 12, '2026-01-01');
        $rows = $s['rows'];
        $this->assertSame(1000.0, $rows[0]['interest']);          // 1% of 100,000
        $this->assertLessThan($rows[0]['interest'], $rows[11]['interest']);
        $this->assertEqualsWithDelta(100000, array_sum(array_column($rows, 'principal')), 0.001);
        $this->assertEqualsWithDelta($s['total_repayable'], array_sum(array_column($rows, 'amount')), 0.001);
        $this->assertGreaterThan(100000, $s['total_repayable']);
        $this->assertEqualsWithDelta($s['total_repayable'] - $rows[0]['amount'], $rows[0]['balance_after'], 0.001);
        $this->assertSame(0.0, $rows[11]['balance_after']);
        foreach ($rows as $r) {
            $this->assertEqualsWithDelta($r['principal'] + $r['interest'], $r['amount'], 0.001);
        }
    }

    public function test_interest_free_loan(): void
    {
        $s = LoanSchedule::build(10000, 'flat', 0, 3, '2026-02-01');
        $this->assertSame(0.0, $s['total_interest']);
        $this->assertSame(10000.0, $s['total_repayable']);
        $this->assertEqualsWithDelta(10000, array_sum(array_column($s['rows'], 'amount')), 0.001);
    }
}
