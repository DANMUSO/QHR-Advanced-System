<?php

namespace App\Dashboard;

use App\Models\User;

/**
 * A dashboard card. The frontend renders it with the Vue component registered under the same key.
 */
abstract class Widget
{
    /** Unique key, matching a component in resources/js/components/dashboard/widgets. */
    abstract public function key(): string;

    abstract public function title(): string;

    /** Grid width on large screens: 'sm' (1/3), 'md' (1/2), 'lg' (2/3) or 'full'. */
    public function size(): string
    {
        return 'sm';
    }

    public function visible(User $user): bool
    {
        return true;
    }

    abstract public function data(User $user): array;
}
