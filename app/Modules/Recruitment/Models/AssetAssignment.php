<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignment extends RecruitmentModel
{
    protected $table = 'asset_assignments';

    public const STATUSES = ['assigned', 'returned', 'lost', 'damaged'];

    protected function casts(): array
    {
        return ['assigned_date' => 'date', 'return_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
