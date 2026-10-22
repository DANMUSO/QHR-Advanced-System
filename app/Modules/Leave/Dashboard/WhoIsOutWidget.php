<?php

namespace App\Modules\Leave\Dashboard;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Leave\Models\LeaveRequest;

/** Who is on approved leave today and during the rest of this week. */
class WhoIsOutWidget extends Widget
{
    public function key(): string
    {
        return 'who_is_out';
    }

    public function title(): string
    {
        return 'Who is out';
    }

    public function data(User $user): array
    {
        $today = today();
        $weekEnd = $today->copy()->endOfWeek();
        $rows = LeaveRequest::taken()->overlapping($today->toDateString(), $weekEnd->toDateString())
            ->with(['employee:id,first_name,last_name,photo_path,department_id,status', 'employee.department:id,name', 'leaveType:id,name,color'])
            ->orderBy('start_date')->limit(100)->get()
            ->filter(fn ($r) => $r->employee && $r->employee->status === 'active');

        $map = fn (LeaveRequest $r) => [
            'id' => $r->id,
            'employee_id' => $r->employee_id,
            'name' => $r->employee->full_name,
            'photo_url' => $r->employee->photo_url,
            'department' => $r->employee->department?->name,
            'type' => $r->leaveType?->name,
            'color' => $r->leaveType?->color,
            'start_date' => $r->start_date->toDateString(),
            'end_date' => $r->end_date->toDateString(),
            'days' => $r->days,
        ];
        $isToday = fn ($r) => $r->start_date->lte($today) && $r->end_date->gte($today);
        $todayRows = $rows->filter($isToday);

        return [
            'today' => $todayRows->map($map)->values(),
            'week' => $rows->reject($isToday)->map($map)->values(),
            'today_count' => $todayRows->count(),
            'week_count' => $rows->unique('employee_id')->count(),
        ];
    }
}
