<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;

/** Birthdays and work anniversaries in the next two weeks. */
class CelebrationsWidget extends Widget
{
    private const WINDOW_DAYS = 14;

    public function key(): string
    {
        return 'celebrations';
    }

    public function title(): string
    {
        return 'Celebrations';
    }

    public function data(User $user): array
    {
        $today = now()->startOfDay();
        $employees = Employee::active()->where(fn ($q) => $q->whereNotNull('date_of_birth')->orWhereNotNull('date_of_joining'))
            ->get(['id', 'first_name', 'last_name', 'photo_path', 'date_of_birth', 'date_of_joining']);

        $nextOccurrence = function (Carbon $date) use ($today): Carbon {
            $d = $date->copy()->year($today->year);
            if ($d->lt($today)) {
                $d->addYear();
            }

            return $d;
        };

        $events = collect();
        foreach ($employees as $e) {
            if ($e->date_of_birth) {
                $next = $nextOccurrence($e->date_of_birth);
                if ($next->diffInDays($today, true) <= self::WINDOW_DAYS) {
                    $events->push(['type' => 'birthday', 'date' => $next->toDateString(), 'employee' => $e, 'years' => null]);
                }
            }
            if ($e->date_of_joining && $e->date_of_joining->lt($today->copy()->subMonths(11))) {
                $next = $nextOccurrence($e->date_of_joining);
                $years = $next->year - $e->date_of_joining->year;
                if ($years >= 1 && $next->diffInDays($today, true) <= self::WINDOW_DAYS) {
                    $events->push(['type' => 'anniversary', 'date' => $next->toDateString(), 'employee' => $e, 'years' => $years]);
                }
            }
        }

        return [
            'items' => $events->sortBy('date')->take(12)->map(fn ($ev) => [
                'type' => $ev['type'],
                'date' => $ev['date'],
                'is_today' => $ev['date'] === $today->toDateString(),
                'years' => $ev['years'],
                'employee' => ['id' => $ev['employee']->id, 'name' => $ev['employee']->full_name, 'photo_url' => $ev['employee']->photo_url],
            ])->values(),
        ];
    }
}
