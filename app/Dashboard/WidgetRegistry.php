<?php

namespace App\Dashboard;

use App\Models\User;
use Illuminate\Support\Collection;

class WidgetRegistry
{
    /** @return Collection<int, Widget> */
    public function all(): Collection
    {
        return collect(config('hr.dashboard_widgets', []))
            ->merge(\App\Support\ModuleRegistry::collect('widgets'))
            ->unique()
            ->map(fn (string $class) => app($class));
    }

    /** @return Collection<int, Widget> */
    public function visibleTo(User $user): Collection
    {
        return $this->all()->filter(fn (Widget $w) => $w->visible($user))->values();
    }

    public function find(string $key): ?Widget
    {
        return $this->all()->first(fn (Widget $w) => $w->key() === $key);
    }
}
