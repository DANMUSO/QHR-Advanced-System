<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RatingScale extends Model
{
    use BelongsToCompany;

    protected $table = 'perf_rating_scales';

    protected $guarded = ['id'];

}
