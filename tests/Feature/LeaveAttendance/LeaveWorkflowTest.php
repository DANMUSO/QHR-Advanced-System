<?php

namespace Tests\Feature\LeaveAttendance;

use App\Models\Employee;
use App\Modules\Employees\Events\EmployeeReinstated;
use App\Modules\Employees\Events\EmployeeTransferred;
use App\Modules\Leave\Dashboard\SupervisorApprovalProvider;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveTransaction;
use App\Modules\Leave\Notifications\LeaveRequestNotification;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Organization\Models\Workflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/** Leave: amend & approve, configurable approval chain, edit, company-transfer carry-over, company settings, year-end split. */
class LeaveWorkflowTest extends LeaveAttendanceTestCase
{
    private function apply($user, string $start, string $end): LeaveRequest
    {
        $res = $this->actingAs($user)->postJson('/api/leave/requests', [
            'leave_type_id' => $this->annual()->id, 'start_date' => $start, 'end_date' => $end,
        ])->assertCreated();

        return LeaveRequest::withoutGlobalScopes()->findOrFail($res->json('data.id'));
    }

    private function taken(Employee $employee): float
    {
        return (float) LeaveBalance::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->where('leave_type_id', $this->annual()->id)->where('year', $this->nextMonday()->year)->value('taken');
    }

    public function test_supervisor_amends_and_approves(): void
    {
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Sam');
        $employee = $this->person(['employee'], $supervisor->employee, 'Eve');
        $mon = $this->nextMonday();
        $leave = $this->apply($employee, $mon->toDateString(), $mon->copy()->addDays(4)->toDateString());
        $this->assertSame(LeaveRequest::PENDING_SUPERVISOR, $leave->status);
        $this->assertEquals(5, $leave->days);

        // Live recount for the amend dialog.
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$leave->id}/preview", [
            'start_date' => $mon->toDateString(), 'end_date' => $mon->copy()->addDays(2)->toDateString(),
        ])->assertOk()->assertJsonPath('days', 3);

        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$leave->id}/amend-approve", [
            'start_date' => $mon->toDateString(), 'end_date' => $mon->copy()->addDays(2)->toDateString(), 'note' => 'Shorter please',
        ])->assertOk()->assertJsonPath('data.status', LeaveRequest::APPROVED)->assertJsonPath('data.amended', true);

        $leave->refresh();
        $this->assertEquals(3, $leave->days);
        $this->assertEquals(5, $leave->original_days);
        $this->assertEquals(3, $this->taken($employee->employee));
        Notification::assertSentTo($employee, LeaveRequestNotification::class, fn ($n) => $n->event === 'amended_approved');
    }

    public function test_configured_chain_routes_through_each_step_and_only_the_last_deducts(): void
    {
        $wf = new Workflow(['name' => 'Leave chain', 'trigger' => 'leave_request', 'is_active' => true, 'steps' => [
            ['type' => 'supervisor', 'label' => 'Supervisor'],
            ['type' => 'role', 'role' => 'hr-admin', 'label' => 'HR'],
        ]]);
        $wf->company_id = $this->company->id;
        $wf->save();

        $hr = $this->person(['hr-admin'], name: 'Hana');
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Sam');
        $employee = $this->person(['employee'], $supervisor->employee, 'Eve');
        $mon = $this->nextMonday();
        $leave = $this->apply($employee, $mon->toDateString(), $mon->copy()->addDay()->toDateString());
        $this->assertSame(0, $leave->approval_step);
        $this->assertSame($supervisor->employee->id, (int) $leave->supervisor_id);

        // Step 1: supervisor. The request moves to the HR step; nothing is deducted yet.
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$leave->id}/approve", ['note' => 'ok'])
            ->assertOk()->assertJsonPath('data.status', LeaveRequest::PENDING_SUPERVISOR);
        $leave->refresh();
        $this->assertSame(1, $leave->approval_step);
        $this->assertSame('hr-admin', $leave->approver_role);
        $this->assertEquals(0, $this->taken($employee->employee));
        $this->assertCount(1, $leave->approval_history);
        Notification::assertSentTo($hr, LeaveRequestNotification::class, fn ($n) => $n->event === 'supervisor_requested' && $n->leave->id === $leave->id);

        // The supervisor can no longer decide; HR sees it in the approvals inbox.
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$leave->id}/approve")->assertForbidden();
        $this->assertSame(0, (new SupervisorApprovalProvider)->countFor($supervisor));
        $this->assertSame(1, (new SupervisorApprovalProvider)->countFor($hr));

        // The final step deducts.
        $this->actingAs($hr)->postJson("/api/leave/requests/{$leave->id}/approve")->assertOk()->assertJsonPath('data.status', LeaveRequest::APPROVED);
        $this->assertEquals(2, $this->taken($employee->employee));
        $this->assertCount(2, $leave->fresh()->approval_history);

        // A rejection at any step needs a reason and ends the request.
        $second = $this->apply($employee, $mon->copy()->addWeek()->toDateString(), $mon->copy()->addWeek()->toDateString());
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$second->id}/reject", [])->assertUnprocessable();
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$second->id}/reject", ['reason' => 'Busy week'])
            ->assertOk()->assertJsonPath('data.status', LeaveRequest::REJECTED);
    }

    public function test_without_a_chain_the_single_supervisor_flow_is_kept(): void
    {
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Sam');
        $employee = $this->person(['employee'], $supervisor->employee, 'Eve');
        $mon = $this->nextMonday();
        $leave = $this->apply($employee, $mon->toDateString(), $mon->toDateString());
        $this->assertNull($leave->approval_step);
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$leave->id}/approve")->assertOk()->assertJsonPath('data.status', LeaveRequest::APPROVED);
    }

    public function test_employee_edits_request_and_supervisor_is_emailed(): void
    {
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Sam');
        $employee = $this->person(['employee'], $supervisor->employee, 'Eve');
        $mon = $this->nextMonday();
        $leave = $this->apply($employee, $mon->toDateString(), $mon->toDateString());

        $this->actingAs($employee)->putJson("/api/leave/requests/{$leave->id}", [
            'leave_type_id' => $this->annual()->id, 'start_date' => $mon->toDateString(), 'end_date' => $mon->copy()->addDay()->toDateString(),
        ])->assertOk()->assertJsonPath('data.days', 2);
        Notification::assertSentTo($supervisor, LeaveRequestNotification::class, fn ($n, $channels) => $n->event === 'edited' && in_array('mail', $channels, true));

        $this->actingAs($supervisor)->putJson("/api/leave/requests/{$leave->id}", [
            'leave_type_id' => $this->annual()->id, 'start_date' => $mon->toDateString(), 'end_date' => $mon->toDateString(),
        ])->assertForbidden();
        $this->actingAs($employee)->postJson("/api/leave/requests/{$leave->id}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->assertSame(LeaveRequest::CANCELLED, $leave->fresh()->status);
    }

    public function test_company_transfer_carries_balances_by_code(): void
    {
        $old = $this->person(['employee'], name: 'Tom')->employee;
        $service = app(LeaveBalanceService::class);
        $oldBalance = $service->ensure($old, $this->annual(), now()->year);
        $service->post($oldBalance, 'adjustment', 3, 'test');
        $available = $oldBalance->refresh()->available;
        $this->assertGreaterThan(0, $available);

        $other = $this->makeCompany('Beta Ltd', 'BET');
        $new = $this->person(['employee'], name: 'Tom', company: $other)->employee;
        event(new EmployeeTransferred($new, $old, ['carry_leave_balances' => true]));

        $newBalance = LeaveBalance::withoutGlobalScopes()->where('employee_id', $new->id)->where('leave_type_id', $this->annual($other)->id)->where('year', now()->year)->first();
        $this->assertEquals($available, $newBalance->available);
        $this->assertEquals(0, $oldBalance->refresh()->available);
        $this->assertTrue(LeaveTransaction::withoutGlobalScopes()->where('leave_balance_id', $newBalance->id)->where('type', 'transfer_in')->exists());
        $this->assertTrue(LeaveTransaction::withoutGlobalScopes()->where('leave_balance_id', $oldBalance->id)->where('type', 'transfer_out')->exists());
        // Types without a carried balance are opened fresh (e.g. sick leave).
        $this->assertTrue(LeaveBalance::withoutGlobalScopes()->where('employee_id', $new->id)->where('leave_type_id', '!=', $this->annual($other)->id)->exists());

        // Idempotent.
        event(new EmployeeTransferred($new, $old, ['carry_leave_balances' => true]));
        $this->assertEquals($available, $newBalance->refresh()->available);
    }

    public function test_transfer_without_carry_and_reinstatement_open_fresh_balances(): void
    {
        $old = $this->person(['employee'], name: 'Tom')->employee;
        $service = app(LeaveBalanceService::class);
        $service->post($service->ensure($old, $this->annual(), now()->year), 'adjustment', 9, 'test');

        $new = $this->person(['employee'], name: 'Tom')->employee;
        event(new EmployeeReinstated($new, $old));
        $fresh = LeaveBalance::withoutGlobalScopes()->where('employee_id', $new->id)->where('leave_type_id', $this->annual()->id)->first();
        $this->assertNotNull($fresh);
        $this->assertFalse(LeaveTransaction::withoutGlobalScopes()->where('leave_balance_id', $fresh->id)->where('type', 'transfer_in')->exists());
    }

    public function test_annual_leave_follows_company_settings_until_the_type_is_edited(): void
    {
        $this->company->forceFill(['settings' => array_merge($this->company->settings ?? [], ['annual_leave_days' => 24, 'monthly_accrual' => 2])])->save();
        $type = $this->annual();
        $this->assertEquals(24, $type->entitlement($this->company->fresh()));
        $this->assertEquals(2, $type->monthlyAccrual($this->company->fresh()));

        $type->forceFill(['customised' => true])->save();
        $this->assertEquals(21, $type->entitlement($this->company->fresh()));
        $this->assertEquals(1.75, $type->monthlyAccrual($this->company->fresh()));
    }

    public function test_leave_spanning_year_end_is_split_across_both_balances(): void
    {
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Sam');
        $employee = $this->person(['employee'], $supervisor->employee, 'Eve');
        $year = now()->year;
        $service = app(LeaveBalanceService::class);
        $service->post($service->ensure($employee->employee, $this->annual(), $year + 1), 'adjustment', 5, 'test');

        $start = Carbon::create($year, 12, 28);
        $end = Carbon::create($year + 1, 1, 6);
        $leave = $this->apply($employee, $start->toDateString(), $end->toDateString());
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$leave->id}/approve")->assertOk();

        $byYear = LeaveTransaction::withoutGlobalScopes()->where('leave_request_id', $leave->id)->where('type', 'deduction')
            ->with('balance')->get()->groupBy(fn ($t) => $t->balance->year)->map(fn ($g) => -$g->sum('days'));
        $this->assertCount(2, $byYear);
        $this->assertEquals($leave->fresh()->days, $byYear->sum());

        // Cancelling credits each year back.
        $this->actingAs($employee)->postJson("/api/leave/requests/{$leave->id}/cancel", ['reason' => 'x'])->assertOk();
        $this->assertEquals(0, LeaveTransaction::withoutGlobalScopes()->where('leave_request_id', $leave->id)->sum('days'));
    }
}
