<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Objective extends Model
{
    protected $table = 'perf_objectives';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function focusArea(): BelongsTo
    {
        return $this->belongsTo(FocusArea::class);
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(Kpi::class)->orderBy('due_date')->orderBy('id');
    }
}
