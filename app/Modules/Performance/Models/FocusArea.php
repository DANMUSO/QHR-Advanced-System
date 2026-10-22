<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FocusArea extends Model
{
    protected $table = 'perf_focus_areas';

    protected $guarded = ['id'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class)->orderBy('sort')->orderBy('id');
    }
}
