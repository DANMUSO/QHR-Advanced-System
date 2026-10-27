<?php

namespace Tests\Feature\LeaveAttendance;

use App\Models\Employee;
use App\Modules\Compliance\Models\DisciplinaryCase;
use App\Modules\Compliance\Models\WarningLetter;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;

/**
 * Role-based QA round: the fixes hold however the data is reached, not only through one screen.
 */
class QaRound3RobustnessTest extends LeaveAttendanceTestCase
{
    public function test_hr_balances_view_never_shows_an_employee_without_balances(): void
    {
        $hr = $this->person(['hr-admin'], null, 'Wanjiru');
        $juma = $this->person(['employee'], null, 'Juma')->employee;
        LeaveBalance::withoutGlobalScopes()->where('employee_id', $juma->id)->delete();

        $row = collect($this->actingAs($hr)->getJson('/api/leave/balances')->assertOk()->json('data'))->firstWhere('id', $juma->id);
        $this->assertNotEmpty($row['balances'], 'balances are created for the viewed year even if Juma never opened My Leave');
    }

    public function test_unpaid_leave_counts_approved_days_as_taken(): void
    {
        $juma = $this->person(['employee'], null, 'Juma');
        $unpaid = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'UNPAID')->first();
        $monday = $this->nextMonday();
        $leave = new LeaveRequest(['employee_id' => $juma->employee->id, 'leave_type_id' => $unpaid->id, 'start_date' => $monday,
            'end_date' => $monday->copy()->addDay(), 'days' => 2, 'status' => LeaveRequest::APPROVED]);
        $leave->company_id = $this->company->id;
        $leave->save();

        $card = collect($this->actingAs($juma)->getJson('/api/leave/my-balances')->assertOk()->json('data'))->firstWhere('code', 'UNPAID');
        // Approved days still ahead are "booked", not yet taken (QA round 5, #30).
        $this->assertEquals(0, $card['taken']);
        $this->assertEquals(2, $card['booked']);
    }

    public function test_expense_categories_come_from_the_server(): void
    {
        $juma = $this->person(['employee'], null, 'Juma');

        $this->actingAs($juma)->getJson('/api/expenses')->assertOk()
            ->assertJsonPath('categories', \App\Modules\Benefits\Models\ExpenseClaim::CATEGORIES);
    }

    public function test_a_warning_outcome_issues_the_warning_however_the_case_is_saved(): void
    {
        $juma = $this->person(['employee'], null, 'Juma')->employee;
        $case = (new DisciplinaryCase(['case_number' => 'DC-T1', 'employee_id' => $juma->id, 'offence' => 'Lateness', 'reported_date' => today()]))
            ->forceFill(['company_id' => $this->company->id]);
        $case->save();

        // Saved directly (no controller): the model event still issues it, and only once.
        $case->update(['outcome' => 'final_warning', 'outcome_notes' => 'Repeated lateness', 'status' => 'closed']);
        $case->update(['status' => 'closed', 'outcome_notes' => 'Repeated lateness (noted)']);

        $warnings = WarningLetter::withoutGlobalScopes()->where('disciplinary_case_id', $case->id)->get();
        $this->assertCount(1, $warnings);
        $this->assertSame('final', $warnings->first()->level);
        $this->assertNotNull($warnings->first()->letter_id, 'the Employees warning letter is generated and linked');
    }
}
