<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Tenancy;

class UpcomingHolidaysWidget extends Widget
{
    public function key(): string
    {
        return 'upcoming_holidays';
    }

    public function title(): string
    {
        return 'Upcoming holidays';
    }

    public function data(User $user): array
    {
        $from = now()->startOfDay();
        $to = now()->addYear();
        $holidays = Holiday::query()->forCompany(Tenancy::companyId() ?? $user->company_id)->get();

        $items = $holidays->flatMap(function (Holiday $h) use ($from, $to) {
            $dates = $h->recurring
                ? collect([$h->date->copy()->year($from->year), $h->date->copy()->year($from->year + 1)])
                : collect([$h->date]);

            return $dates->filter(fn ($d) => $d->between($from, $to))
                ->map(fn ($d) => ['name' => $h->name, 'date' => $d->toDateString(), 'weekday' => $d->format('l'), 'days_away' => (int) $from->diffInDays($d)]);
        })->sortBy('date')->take(5)->values();

        return ['items' => $items];
    }
}
