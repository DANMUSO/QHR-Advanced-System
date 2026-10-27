<?php

namespace Tests\Feature\LeaveAttendance;

use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Payroll\AttendancePayrollInputs;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** QA round 5: absentees in the register / payroll without the nightly job; leave taken vs booked. */
class QaRound5Test extends LeaveAttendanceTestCase
{
    /** A finished weekday (Mon–Fri) at least two days ago. */
    private function pastWorkday(): CarbonImmutable
    {
        $d = CarbonImmutable::today()->subDays(2);
        while ($d->isWeekend()) {
            $d = $d->subDay();
        }

        return $d;
    }

    private function joined(Employee $e, string $date): Employee
    {
        $e->forceFill(['date_of_joining' => $date])->save();

        return $e;
    }

    public function test_register_lists_everyone_and_marks_no_punch_staff_absent_without_the_nightly_job(): void
    {
        $day = $this->pastWorkday();
        $hr = $this->person(['hr-admin'], name: 'Hana');
        $this->joined($hr->employee, '2020-01-01');
        $present = $this->joined($this->person(['employee'], name: 'Juma')->employee, '2020-01-01');
        $absent = $this->joined($this->person(['employee'], name: 'Zawadi')->employee, '2020-01-01');
        $newcomer = $this->joined($this->person(['employee'], name: 'Neema')->employee, $day->addDay()->toDateString());
        $leaver = $this->joined($this->person(['employee'], name: 'Imani')->employee, '2020-01-01');
        DB::table('employee_exits')->insert(['company_id' => $this->company->id, 'employee_id' => $leaver->id, 'exit_date' => $day->subDay()->toDateString(), 'reason' => 'resignation', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $leaver->forceFill(['status' => 'exited'])->save();

        // Juma clocked in; nobody ran attendance:compute.
        $punch = new AttendancePunch(['employee_id' => $present->id, 'punched_at' => $day->setTime(8, 0), 'direction' => 'in', 'source' => 'manual']);
        $punch->company_id = $this->company->id;
        $punch->save();
        $this->assertSame(0, AttendanceDay::withoutGlobalScopes()->whereDate('date', $day)->where('status', 'absent')->count());

        $res = $this->actingAs($hr)->getJson('/api/attendance/daily?date='.$day->toDateString())->assertOk();
        $byEmployee = collect($res->json('data'))->keyBy('employee_id');
        $this->assertSame('absent', $byEmployee[$absent->id]['status']);
        $this->assertSame('absent', $byEmployee[$hr->employee->id]['status']);
        $this->assertNotSame('absent', $byEmployee[$present->id]['status']);
        $this->assertFalse($byEmployee->has($newcomer->id), 'not employed yet');
        $this->assertFalse($byEmployee->has($leaver->id), 'already left');
        $this->assertSame(2, $res->json('summary.absent'));

        // Absent rows are stored, so payroll sees them.
        $this->assertTrue(AttendanceDay::withoutGlobalScopes()->where('employee_id', $absent->id)->whereDate('date', $day)->where('status', 'absent')->exists());
        $this->assertFalse(AttendanceDay::withoutGlobalScopes()->where('employee_id', $newcomer->id)->whereDate('date', $day)->exists());

        // Weekend days list people as off, not absent.
        $saturday = CarbonImmutable::today()->previous(CarbonImmutable::SATURDAY);
        $weekend = collect($this->actingAs($hr)->getJson('/api/attendance/daily?date='.$saturday->toDateString())->json('data'))->keyBy('employee_id');
        $this->assertSame('weekend', $weekend[$absent->id]['status']);
    }

    public function test_payroll_inputs_count_absences_for_days_never_computed(): void
    {
        $day = $this->pastWorkday();
        $zawadi = $this->joined($this->person(['employee'], name: 'Zawadi')->employee, $day->toDateString());

        // A company that keeps no attendance at all never reports absences.
        $this->assertEquals(0.0, (new AttendancePayrollInputs)->inputsFor($zawadi, $day, $day)['days_absent']);

        // Once anyone clocks in, the company keeps attendance: no punch on a working day is an absence.
        $juma = $this->joined($this->person(['employee'], name: 'Juma')->employee, '2020-01-01');
        $punch = new AttendancePunch(['employee_id' => $juma->id, 'punched_at' => $day->setTime(8, 0), 'direction' => 'in', 'source' => 'manual']);
        $punch->company_id = $this->company->id;
        $punch->save();
        app(AttendanceService::class)->invalidateFinalised($this->company->id, $day);

        $inputs = (new AttendancePayrollInputs)->inputsFor($zawadi, $day, $day);
        $this->assertEquals(1.0, $inputs['days_absent']);

        // Joining date moved later: the day reopens and the absence goes away.
        $zawadi->forceFill(['date_of_joining' => $day->addDay()->toDateString()])->save();
        app()->forgetInstance(AttendanceService::class);
        $this->assertEquals(0.0, (new AttendancePayrollInputs)->inputsFor($zawadi->fresh(), $day, $day)['days_absent']);
    }

    public function test_leave_cards_split_taken_from_booked(): void
    {
        $user = $this->person(['employee'], name: 'Juma');
        $employee = $user->employee;
        $this->joined($employee, '2020-01-01');
        $type = $this->annual();
        $service = app(LeaveBalanceService::class);
        $balance = $service->ensure($employee, $type, (int) now()->year);
        $before = (float) $balance->fresh()->available;

        $book = function (CarbonImmutable $start, CarbonImmutable $end, float $days) use ($employee, $type, $service, $balance) {
            $r = new LeaveRequest(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'days' => $days, 'status' => LeaveRequest::APPROVED, 'reason' => 'Test']);
            $r->company_id = $this->company->id;
            $r->save();
            $service->post($balance->fresh(), 'deduction', -$days, 'test', null, $r, "request:{$r->id}", now());
        };
        // Two days already taken earlier this year, three days booked ahead.
        $pastMonday = CarbonImmutable::today()->startOfWeek()->subWeeks(2);
        if ($pastMonday->year !== now()->year) {
            $this->markTestSkipped('Too early in the year for a past leave.');
        }
        $book($pastMonday, $pastMonday->addDay(), 2);
        $futureMonday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY)->addWeek();
        if ($futureMonday->year !== now()->year) {
            $this->markTestSkipped('Too late in the year for a booked leave.');
        }
        $book($futureMonday, $futureMonday->addDays(2), 3);

        $card = collect($this->actingAs($user)->getJson('/api/leave/my-balances')->assertOk()->json('data'))->firstWhere('code', 'ANNUAL')
            ?? collect($service->summary($employee->fresh()))->firstWhere('code', 'ANNUAL');
        $this->assertEquals(2, $card['taken']);
        $this->assertEquals(3, $card['booked']);
        $this->assertEquals($before - 5, $card['available'], 'available still subtracts both');
    }
}
