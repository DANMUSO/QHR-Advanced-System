<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipCheckin extends Model
{
    use BelongsToCompany;
    public const RECOMMENDATIONS = ['continue', 'extend', 'complete', 'escalate'];

    protected $table = 'perf_pip_checkins';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meeting_date' => 'date:Y-m-d'];
    }

    public function pip(): BelongsTo
    {
        return $this->belongsTo(Pip::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
