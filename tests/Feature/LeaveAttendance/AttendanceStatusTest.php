<?php

namespace Tests\Feature\LeaveAttendance;

use Carbon\Carbon;

/** QA round 2: day statuses in My Attendance — absent past days, today in progress / not clocked in, weekends off. */
class AttendanceStatusTest extends LeaveAttendanceTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function byDate($user): array
    {
        $rows = $this->actingAs($user)->getJson('/api/attendance/mine?from=2026-10-19&to=2026-10-25')->assertOk()->json('data');

        return collect($rows)->keyBy('date')->all();
    }

    public function test_statuses_for_past_today_future_and_weekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00')); // a Wednesday
        $supervisor = $this->person(['supervisor', 'employee'], name: 'Peter');
        $user = $this->person(['employee'], $supervisor->employee, 'Jane');
        $user->employee->forceFill(['date_of_joining' => '2026-01-05'])->save();

        // Approved leave on Tuesday.
        $id = $this->actingAs($user)->postJson('/api/leave/requests', [
            'leave_type_id' => $this->annual()->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20',
        ])->assertCreated()->json('data.id');
        $this->actingAs($supervisor)->postJson("/api/leave/requests/{$id}/approve")->assertOk();

        $days = $this->byDate($user->fresh());
        $this->assertSame('absent', $days['2026-10-19']['status']);
        $this->assertSame('Absent', $days['2026-10-19']['status_label']);
        $this->assertSame('on_leave', $days['2026-10-20']['status']);
        $this->assertSame('not_in', $days['2026-10-21']['status']);
        $this->assertSame('Not clocked in yet', $days['2026-10-21']['status_label']);
        $this->assertSame('upcoming', $days['2026-10-22']['status']);
        $this->assertSame('weekend', $days['2026-10-24']['status']);
        $this->assertSame('Off', $days['2026-10-24']['status_label']);

        // Clock in: today is in progress and the clock panel offers Clock Out next.
        $this->actingAs($user)->postJson('/api/attendance/punch', ['direction' => 'in'])->assertCreated();
        $this->assertSame('in_progress', $this->byDate($user)['2026-10-21']['status']);
        $this->actingAs($user)->getJson('/api/attendance/me/today')->assertOk()
            ->assertJsonPath('clocked_in', true)->assertJsonPath('next_direction', 'out');
    }

    public function test_days_before_joining_have_no_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00'));
        $user = $this->person(['employee'], name: 'New');
        $user->employee->forceFill(['date_of_joining' => '2026-10-21'])->save();

        $days = $this->byDate($user->fresh());
        $this->assertNull($days['2026-10-19']['status']);
        $this->assertSame('not_in', $days['2026-10-21']['status']);
    }
}
