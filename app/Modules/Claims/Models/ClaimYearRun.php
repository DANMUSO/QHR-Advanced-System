<?php

namespace App\Modules\Claims\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimYearRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'ran_at' => 'datetime'];
    }
}
