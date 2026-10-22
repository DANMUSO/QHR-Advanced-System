<?php

namespace Tests\Feature\LeaveAttendance;

use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Notifications\AttendanceDecisionNotification;
use App\Modules\Attendance\Payroll\AttendancePayrollInputs;
use App\Modules\Attendance\Services\DayCalculator;
use App\Modules\Attendance\Support\AttendanceRules;
use App\Modules\Payroll\Events\PayrollRunApproved;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Attendance: supervisors decide their own team's overtime only; overtime is marked paid by the approving payroll run. */
class AttendanceScopeTest extends LeaveAttendanceTestCase
{
    private function overtime(Employee $employee, string $date, string $status = 'pending', int $minutes = 120): AttendanceOvertime
    {
        $o = new AttendanceOvertime(['employee_id' => $employee->id, 'date' => $date, 'day_type' => 'working', 'minutes' => $minutes, 'rate' => 1.5, 'status' => $status]);
        $o->company_id = $employee->company_id;
        $o->save();

        return $o;
    }

    public function test_supervisor_decides_own_team_overtime_only_and_cannot_manage_settings(): void
    {
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Sam');
        $mine = $this->person(['employee'], $supervisor->employee, 'Eve');
        $other = $this->person(['employee'], name: 'Olu');
        $this->assertFalse($supervisor->can('attendance.manage'));

        $ot = $this->overtime($mine->employee, today()->subDays(2)->toDateString());
        $foreign = $this->overtime($other->employee, today()->subDays(2)->toDateString());

        $this->actingAs($supervisor)->getJson('/api/attendance/overtime')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ot->id)->assertJsonPath('data.0.can_decide', true);
        $this->actingAs($supervisor)->postJson("/api/attendance/overtime/{$ot->id}/approve")->assertOk();
        $this->assertSame('approved', $ot->fresh()->status);
        Notification::assertSentTo($mine, AttendanceDecisionNotification::class, fn ($n, $channels) => $n->status === 'approved' && in_array('mail', $channels, true));

        $this->actingAs($supervisor)->postJson("/api/attendance/overtime/{$foreign->id}/approve")->assertForbidden();
        // Rejecting needs a reason.
        $second = $this->overtime($mine->employee, today()->subDays(3)->toDateString());
        $this->actingAs($supervisor)->postJson("/api/attendance/overtime/{$second->id}/reject")->assertUnprocessable();

        // Company-wide administration stays with attendance.manage.
        foreach (['/api/attendance/devices', '/api/attendance/geofencing', '/api/attendance/shifts', '/api/attendance/settings'] as $url) {
            $this->actingAs($supervisor)->getJson($url)->assertForbidden();
        }
        $this->actingAs($mine)->getJson('/api/attendance/overtime')->assertForbidden();
    }

    public function test_approved_payroll_run_marks_overtime_paid_and_inputs_skip_it(): void
    {
        $employee = $this->person(['employee'], name: 'Eve')->employee;
        $period = CarbonImmutable::parse('2026-09-01');
        $inPeriod = $this->overtime($employee, '2026-09-10', 'approved', 120);
        $late = $this->overtime($employee, '2026-08-20', 'approved', 60);      // approved after August closed
        $pending = $this->overtime($employee, '2026-09-12', 'pending', 30);
        $next = $this->overtime($employee, '2026-10-02', 'approved', 45);      // next period

        $inputs = (new AttendancePayrollInputs)->inputsFor($employee, $period, $period->endOfMonth());
        $this->assertEquals(3.0, $inputs['overtime_hours_normal']);

        $runId = DB::table('payroll_runs')->insertGetId([
            'company_id' => $this->company->id, 'title' => 'September 2026', 'period' => '2026-09-01', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'pay_date' => '2026-09-28', 'type' => 'regular', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payroll_run_employees')->insert(['company_id' => $this->company->id, 'payroll_run_id' => $runId, 'employee_id' => $employee->id, 'created_at' => now(), 'updated_at' => now()]);
        event(new PayrollRunApproved(PayrollRun::withoutGlobalScopes()->findOrFail($runId)));

        foreach ([$inPeriod, $late] as $o) {
            $this->assertSame('paid', $o->fresh()->status);
            $this->assertSame($runId, (int) $o->fresh()->payroll_run_id);
        }
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('approved', $next->fresh()->status);

        // Re-running September's inputs no longer pays the settled overtime.
        $this->assertEquals(0.0, (new AttendancePayrollInputs)->inputsFor($employee, $period, $period->endOfMonth())['overtime_hours_normal']);
        $this->assertEquals(0.75, (new AttendancePayrollInputs)->inputsFor($employee, $period->addMonth(), $period->addMonth()->endOfMonth())['overtime_hours_normal']);
    }

    public function test_hourly_leave_excuses_only_its_window(): void
    {
        $rules = AttendanceRules::fromArray(['work_start' => '08:00', 'late_grace_minutes' => 10] + AttendanceRules::DEFAULTS);
        $date = CarbonImmutable::parse('2026-10-19 00:00'); // Monday
        $at = fn (string $t) => ['at' => CarbonImmutable::parse("2026-10-19 $t")];
        $calc = new DayCalculator;

        // Morning leave until 10:00, arrived 10:05: present (within grace of the moved start), not on leave.
        $r = $calc->calculate($date, [$at('10:05'), $at('17:00')], $rules, 'working', false, true, ['from' => '08:00', 'to' => '10:00']);
        $this->assertSame('present', $r['status']);
        $this->assertSame(0, $r['late_minutes']);

        // Without the leave the same punches are late.
        $this->assertSame('late', $calc->calculate($date, [$at('10:05'), $at('17:00')], $rules)['status']);

        // Afternoon leave from 15:00: leaving at 15:00 is not an early departure.
        $r = $calc->calculate($date, [$at('08:00'), $at('15:00')], $rules, 'working', false, true, ['from' => '15:00', 'to' => '17:00']);
        $this->assertSame(0, $r['early_leave_minutes']);
        $this->assertSame('present', $r['status']);
    }
}
