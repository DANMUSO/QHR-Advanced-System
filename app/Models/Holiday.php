<?php

namespace App\Models;

use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Public / company holidays. A null company_id means the holiday applies to every company. */
class Holiday extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'recurring' => 'boolean'];
    }

    public function scopeForCompany(Builder $q, ?int $companyId): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('company_id')->when($companyId, fn ($x) => $x->orWhere('company_id', $companyId)));
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->forCompany(Tenancy::companyId());
    }

    /** Holiday dates (Y-m-d) between two dates for a company, expanding recurring holidays. */
    public static function datesBetween(CarbonInterface $from, CarbonInterface $to, ?int $companyId): Collection
    {
        return static::query()->forCompany($companyId)->get()->flatMap(function (Holiday $h) use ($from, $to) {
            if (! $h->recurring) {
                return $h->date->between($from, $to) ? [$h->date->toDateString()] : [];
            }
            $dates = [];
            for ($y = $from->year; $y <= $to->year; $y++) {
                $d = $h->date->copy()->year($y);
                if ($d->between($from, $to)) {
                    $dates[] = $d->toDateString();
                }
            }

            return $dates;
        })->unique()->values();
    }
}
