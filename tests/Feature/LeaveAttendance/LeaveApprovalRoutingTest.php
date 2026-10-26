<?php

namespace Tests\Feature\LeaveAttendance;

use App\Modules\Leave\Dashboard\SupervisorApprovalProvider;
use App\Modules\Leave\Models\LeaveRequest;

/** QA round 2: a supervisor whose account is not yet activated still receives their team's leave approvals. */
class LeaveApprovalRoutingTest extends LeaveAttendanceTestCase
{
    public function test_unactivated_supervisor_is_assigned_and_sees_the_request(): void
    {
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Peter');
        $supervisor->forceFill(['activated_at' => null])->save();
        $employee = $this->person(['employee'], $supervisor->employee, 'Jane');
        $hr = $this->person(['hr-admin'], name: 'Grace');

        $mon = $this->nextMonday();
        $id = $this->actingAs($employee)->postJson('/api/leave/requests', [
            'leave_type_id' => $this->annual()->id, 'start_date' => $mon->toDateString(), 'end_date' => $mon->toDateString(),
        ])->assertCreated()->json('data.id');
        $leave = LeaveRequest::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame(LeaveRequest::PENDING_SUPERVISOR, $leave->status);
        $this->assertSame($supervisor->employee->id, (int) $leave->supervisor_id);
        // Activity is filed under the leave log, not "default".
        $this->assertTrue(\Spatie\Activitylog\Models\Activity::where('subject_type', LeaveRequest::class)->where('subject_id', $id)->where('log_name', 'leave')->exists());
        $this->assertFalse(\Spatie\Activitylog\Models\Activity::where('subject_type', LeaveRequest::class)->where('log_name', 'default')->exists());

        $this->assertSame(1, (new SupervisorApprovalProvider)->countFor($supervisor->fresh()));
        $this->actingAs($supervisor->fresh())->getJson('/api/leave/approvals')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.can_decide', true)->assertJsonPath('data.0.unassigned', false);

        // HR sees who it waits on.
        $this->actingAs($hr)->getJson('/api/leave/approvals')->assertOk()
            ->assertJsonPath('data.0.approver', 'Waiting for '.$supervisor->employee->full_name);
    }

    public function test_request_without_a_reachable_approver_is_flagged_unassigned_for_hr(): void
    {
        $employee = $this->person(['employee'], name: 'Lone');
        $hr = $this->person(['hr-admin'], name: 'Grace');
        $mon = $this->nextMonday();
        $this->actingAs($employee)->postJson('/api/leave/requests', [
            'leave_type_id' => $this->annual()->id, 'start_date' => $mon->toDateString(), 'end_date' => $mon->toDateString(),
        ])->assertCreated();

        $this->actingAs($hr)->getJson('/api/leave/approvals')->assertOk()
            ->assertJsonPath('data.0.unassigned', true)
            ->assertJsonPath('data.0.approver', 'No supervisor – HR approves');
        $this->assertSame(1, (new SupervisorApprovalProvider)->countFor($hr));
    }
}
