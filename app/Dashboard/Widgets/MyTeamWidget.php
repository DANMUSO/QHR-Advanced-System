<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;

class MyTeamWidget extends Widget
{
    public function key(): string
    {
        return 'my_team';
    }

    public function title(): string
    {
        return 'My team';
    }

    public function visible(User $user): bool
    {
        return (bool) $user->employee?->directReports()->active()->exists();
    }

    public function data(User $user): array
    {
        $reports = $user->employee->directReports()->active()->with('position:id,title')
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'position_id', 'photo_path', 'email', 'phone']);

        // Modules (Leave, Attendance) may report live statuses: 'team_status' => fn (Collection $ids) => [id => 'on_leave' | 'absent' | 'present'].
        $statuses = [];
        foreach (\App\Support\ModuleRegistry::callbacks('team_status') as $resolver) {
            $statuses = array_replace($statuses, $resolver($reports->pluck('id')));
        }

        return [
            'count' => $reports->count(),
            'members' => $reports->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->full_name,
                'photo_url' => $e->photo_url,
                'position' => $e->position?->title,
                'email' => $e->email,
                'phone' => $e->phone,
                'status' => $statuses[$e->id] ?? 'available',
            ]),
        ];
    }
}
