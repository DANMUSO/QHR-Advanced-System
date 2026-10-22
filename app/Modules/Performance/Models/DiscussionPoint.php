<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscussionPoint extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['open', 'completed', 'extended', 'escalated'];

    protected $table = 'perf_discussion_points';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['deadline' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
