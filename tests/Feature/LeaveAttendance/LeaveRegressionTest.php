<?php

namespace Tests\Feature\LeaveAttendance;

use App\Models\Company;
use App\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveDayCounter;

/**
 * Regressions from the Journey QA run (22 Oct 2026):
 *  - a half-typed year in the date picker (0202-11-04) made the live preview count ~465,000 days and time out;
 *  - a supervisor who activated after the request was submitted never received it until the nightly reroute;
 *  - a company created in the app had no leave types until the first monthly accrual run.
 */
class LeaveRegressionTest extends LeaveAttendanceTestCase
{
    public function test_preview_and_apply_reject_implausible_dates_quickly(): void
    {
        $employee = $this->person(['employee']);
        $type = $this->annual();
        $monday = $this->nextMonday()->toDateString();

        $started = microtime(true);
        foreach (['0002-11-04', '0202-11-04', '1926-11-04'] as $typo) {
            $this->actingAs($employee)->postJson('/api/leave/preview', ['leave_type_id' => $type->id, 'start_date' => $typo, 'end_date' => $monday])
                ->assertStatus(422)->assertJsonValidationErrors('start_date');
        }
        $this->assertLessThan(5, microtime(true) - $started, 'invalid dates must be rejected before any day counting');

        $this->actingAs($employee)->postJson('/api/leave/preview', ['leave_type_id' => $type->id, 'start_date' => $monday, 'end_date' => '2026-1-5'])
            ->assertStatus(422);
        $this->actingAs($employee)->postJson('/api/leave/requests', ['leave_type_id' => $type->id, 'start_date' => '0202-11-04', 'end_date' => $monday])
            ->assertStatus(422);
        $this->actingAs($employee)->postJson('/api/leave/preview', ['leave_type_id' => $type->id, 'start_date' => $monday, 'end_date' => $monday])
            ->assertOk()->assertJsonPath('days', 1);
    }

    public function test_counter_refuses_huge_ranges_and_next_working_day_is_bounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $company = $this->company;
            $company->forceFill(['settings' => array_merge((array) $company->settings, ['weekend_days' => [0, 1, 2, 3, 4, 5, 6]])])->save();
            $next = app(LeaveDayCounter::class)->nextWorkingDay(now(), $company->fresh());
            $this->assertTrue($next->lte(now()->addDays(32)), 'an all-weekend company must not loop forever');
        } finally {
            LeaveDayCounter::countDays(now()->setDate(202, 11, 4), now(), 'working');
        }
    }

    public function test_supervisor_receives_waiting_request_when_they_activate(): void
    {
        $supervisor = $this->person(['supervisor', 'employee'], null, 'Peter');
        $supervisor->forceFill(['activated_at' => null])->save();
        $employee = $this->person(['employee'], $supervisor->employee, 'Jane');
        $monday = $this->nextMonday();

        $this->actingAs($employee)->postJson('/api/leave/requests', [
            'leave_type_id' => $this->annual()->id, 'start_date' => $monday->toDateString(), 'end_date' => $monday->copy()->addDay()->toDateString(),
        ])->assertCreated();
        $leave = LeaveRequest::withoutGlobalScopes()->latest('id')->first();
        // Not activated yet still counts: the supervisor gets the request by email and can act once activated.
        $this->assertSame($supervisor->employee->id, (int) $leave->supervisor_id);

        $supervisor->forceFill(['activated_at' => now()])->save();
        app()->terminate(); // afterResponse jobs run here

        $this->assertSame($supervisor->employee->id, (int) $leave->fresh()->supervisor_id);
    }

    public function test_a_new_company_gets_the_default_leave_catalogue(): void
    {
        $company = Company::create(['name' => 'Fresh Co', 'code' => 'FRS', 'is_active' => true]);

        $codes = LeaveType::withoutGlobalScopes()->where('company_id', $company->id)->pluck('code');
        $this->assertContains('ANNUAL', $codes);
        $this->assertContains('SICK_FULL', $codes);
        $this->assertContains('MATERNITY', $codes);
    }
}
