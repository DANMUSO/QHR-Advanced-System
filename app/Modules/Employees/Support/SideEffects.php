<?php

namespace App\Modules\Employees\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs optional side effects (event listeners, follow-up automation) so that a failure is
 * logged instead of breaking, or half-committing, the main operation that triggered it.
 */
class SideEffects
{
    public static function run(string $label, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::error("Side effect failed: {$label}", [
                'exception' => $e,
            ]);

            return null;
        }
    }

    /** Dispatches an event; listener failures are logged and never bubble up to the caller. */
    public static function dispatch(object $event): void
    {
        static::run('dispatch '.class_basename($event), fn () => event($event));
    }
}
