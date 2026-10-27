<?php

namespace App\Models\Concerns;

use DateTimeInterface;

/**
 * Model dates go to JSON with the app's local offset (2026-10-27T00:00:00+03:00) instead of Eloquent's UTC "Z" form,
 * which turned Nairobi dates into the previous day wherever the UI reads the date part. Same instant, local reading.
 */
trait SerializesLocalDates
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return \Illuminate\Support\Carbon::instance($date)->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP');
    }
}
