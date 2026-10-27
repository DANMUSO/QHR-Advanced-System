<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    use SerializesLocalDates;

    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if (! auth()->hasUser()) {
                return; // console, queue and scheduled jobs see every company
            }
            $companyId = Tenancy::companyId();
            if ($companyId !== null) {
                $builder->where($builder->qualifyColumn('company_id'), $companyId);
            } elseif (! Tenancy::isGlobal()) {
                $builder->whereRaw('1 = 0');
            }
        });

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = Tenancy::companyIdForWrite();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
