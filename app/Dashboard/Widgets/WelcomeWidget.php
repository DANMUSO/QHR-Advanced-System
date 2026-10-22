<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Tenancy;

class WelcomeWidget extends Widget
{
    public function key(): string   { return 'welcome'; }
    public function title(): string { return 'Welcome'; }
    public function size(): string  { return 'full'; }

    public function data(User $user): array
    {
        $hour     = (int) now()->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $employee = $user->employee;
        $isAdmin  = $user->can('dashboard.hr') || $user->hasRole('super-admin');

        $nextHoliday = Holiday::datesBetween(now()->startOfDay(), now()->addYear(), Tenancy::companyId() ?? $user->company_id)->sort()->first();
        $holidayName = $nextHoliday ? Holiday::query()->forCompany($user->company_id)->get()
            ->first(fn ($h) => $h->date->format('m-d') === substr($nextHoliday, 5) || $h->date->toDateString() === $nextHoliday)?->name : null;

        return [
            'is_admin'            => $isAdmin,
            'greeting'            => $greeting,
            'first_name'          => $employee?->first_name ?? strtok($user->name, ' '),
            'today'               => now()->toDateString(),
            'position'            => $employee?->position?->title,
            'department'          => $employee?->department?->name,
            'years_of_service'    => $employee?->date_of_joining ? round($employee->date_of_joining->diffInYears(now()), 1) : null,
            'profile_completeness'=> $employee?->profileCompleteness(),
            'last_login_at'       => $user->loginActivities()->where('event', 'login')->latest('created_at')->skip(1)->value('created_at'),
            'next_holiday'        => $nextHoliday ? ['date' => $nextHoliday, 'name' => $holidayName] : null,
        ];
    }
}
