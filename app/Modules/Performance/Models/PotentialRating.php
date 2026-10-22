<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Potential (1 low – 3 high) for the 9-box grid; child of a company-owned Cycle. */
class PotentialRating extends Model
{
    use BelongsToCompany;
    protected $table = 'succ_potential_ratings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['potential' => 'integer'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
