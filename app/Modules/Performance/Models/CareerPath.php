<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CareerPath extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'succ_career_paths';

    protected $guarded = ['id', 'company_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return ['min_years' => 'integer'];
    }

    public function fromPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'from_position_id');
    }

    public function toPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'to_position_id');
    }
}
