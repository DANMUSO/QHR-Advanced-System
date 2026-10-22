<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToCompany;

    protected $table = 'perf_units';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_percentage' => 'boolean'];
    }
}
