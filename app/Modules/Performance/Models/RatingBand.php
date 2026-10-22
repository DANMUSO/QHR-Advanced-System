<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RatingBand extends Model
{
    use BelongsToCompany;

    protected $table = 'perf_rating_bands';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['min_score' => 'float'];
    }
}
