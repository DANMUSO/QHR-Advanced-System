<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Frequency extends Model
{
    use BelongsToCompany;

    protected $table = 'perf_frequencies';

    protected $guarded = ['id'];

}
