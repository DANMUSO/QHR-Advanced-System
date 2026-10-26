<?php

namespace Tests\Feature\LeaveAttendance;

use App\Contracts\PayrollInputProvider;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Attendance\Payroll\AttendancePayrollInputs;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Support\PayrollSettings;
use App\Modules\Recruitment\Models\Requisition;
use App\Support\ActivityCauser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

/** Gaps from the attendance / recruitment / audit QA round. */
class QaRound4Test extends LeaveAttendanceTestCase
{
    private function onPayroll(User $user, float $basic = 60000): void
    {
        PayrollPayProfile::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $user->employee->id, 'basic_salary' => $basic, 'payment_method' => 'cash']);
        PayrollSalaryHistory::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $user->employee->id, 'basic_salary' => $basic, 'effective_date' => '2025-01-01', 'reason' => 'initial']);
        $user->employee->forceFill(['date_of_joining' => '2025-01-01', 'kra_pin' => 'A001234567Z'])->save();
    }

    /** Attendance reports 4 unexplained absences for everyone. */
    private function fourAbsences(): void
    {
        app()->instance(AttendancePayrollInputs::class, new class implements PayrollInputProvider {
            public function inputsFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
            {
                return ['days_absent' => 4.0];
            }
        });
    }

    private function runLine(User $officer, User $juma, string $period = '2026-08'): PayrollRunEmployee
    {
        $run = $this->actingAs($officer)->postJson('/api/payroll/runs', ['period' => $period, 'type' => 'regular', 'calculate' => true])->assertCreated()->json('data.id');

        return PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run)->where('employee_id', $juma->employee->id)->firstOrFail();
    }

    public function test_unexplained_absences_are_flagged_by_default_and_deducted_when_the_company_chooses(): void
    {
        $officer = $this->person(['payroll-officer'], null, 'Olive');
        $juma = $this->person(['employee'], null, 'Juma');
        $this->onPayroll($juma);
        $this->fourAbsences();

        $line = $this->runLine($officer, $juma);
        $this->assertContains('unexplained_absence', collect($line->flags)->pluck('code')->all());
        $this->assertEquals(0, (float) $line->unpaid_days, 'flag only: no deduction');

        PayrollSettings::save($this->company, ['absence_policy' => 'deduct'] + PayrollSettings::all($this->company));
        $line = $this->runLine($officer, $juma, '2026-09');
        $this->assertEquals(4, (float) $line->unpaid_days, 'deduct: absences are paid like unpaid leave');
    }

    public function test_timesheets_need_a_finished_week_and_an_explanation_for_exceptions(): void
    {
        $juma = $this->person(['employee'], null, 'Juma');
        $juma->employee->forceFill(['date_of_joining' => '2025-01-01'])->save();

        $thisMonday = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
        if (CarbonImmutable::today()->isSunday()) {
            $this->markTestSkipped('On a Sunday the current week counts as over.');
        }
        $this->actingAs($juma)->postJson('/api/attendance/timesheets/submit', ['week' => $thisMonday->toDateString()])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'not over yet'));

        $lastWeek = $thisMonday->subWeek()->toDateString();
        $this->actingAs($juma)->postJson('/api/attendance/timesheets/submit', ['week' => $lastWeek])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $sheet = $this->actingAs($juma)->postJson('/api/attendance/timesheets/submit', ['week' => $lastWeek, 'note' => 'Worked from the field office'])
            ->assertCreated()->json('data');
        $this->assertGreaterThan(0, $sheet['absent_days'] + $sheet['incomplete_days'], 'the approver sees the exceptions');
    }

    public function test_supervisors_raise_their_own_requisitions_and_hr_runs_the_pipeline(): void
    {
        $zawadi = $this->person(['supervisor', 'employee'], null, 'Zawadi');
        $wanjiru = $this->person(['hr-admin'], null, 'Wanjiru');
        $position = \App\Models\Position::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'title' => 'Field Officer']);
        $dept = \App\Models\Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Field']);
        $other = (new Requisition(['requisition_number' => 'REQ-T1', 'position_id' => $position->id, 'department_id' => $dept->id, 'vacancies' => 1, 'type' => 'permanent', 'status' => 'pending_approval', 'requested_by' => $wanjiru->id, 'justification' => 'x']))
            ->forceFill(['company_id' => $this->company->id]);
        $other->save();

        $this->actingAs($zawadi)->postJson('/api/recruitment/requisitions', ['position_id' => $position->id, 'department_id' => $dept->id, 'vacancies' => 2, 'type' => 'permanent', 'justification' => 'Team growth'])->assertCreated();
        $mine = collect($this->actingAs($zawadi)->getJson('/api/recruitment/requisitions')->assertOk()->json('data'));
        $this->assertCount(1, $mine, 'a supervisor sees only their own requisitions');
        $this->actingAs($zawadi)->getJson('/api/recruitment/applicants')->assertForbidden();
        $this->actingAs($zawadi)->putJson("/api/recruitment/requisitions/{$other->id}", ['vacancies' => 3])->assertForbidden();

        $id = $mine->first()['id'];
        $this->actingAs($wanjiru)->postJson("/api/recruitment/requisitions/{$id}/approve")->assertOk();
        $this->actingAs($wanjiru)->getJson('/api/recruitment/applicants')->assertOk();
    }

    public function test_actions_during_impersonation_name_the_admin(): void
    {
        $admin = $this->person(['hr-admin'], null, 'Admin');
        $zawadi = $this->person(['employee'], null, 'Zawadi');
        $monday = $this->nextMonday();

        // A browser request (Referer = the SPA) is stateful, so the impersonation session is available, as in the app.
        $this->withHeader('Referer', 'http://127.0.0.1:8000')->withSession(['impersonator_id' => $admin->id])->actingAs($zawadi)->postJson('/api/leave/requests', [
            'leave_type_id' => $this->annual()->id, 'start_date' => $monday->toDateString(), 'end_date' => $monday->toDateString(),
        ])->assertCreated();

        $activity = Activity::where('causer_id', $zawadi->id)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $activity->properties['impersonated_by']['id'] ?? null);
        $this->assertSame("{$admin->name} (as {$zawadi->name})", ActivityCauser::label($activity));
    }
}
