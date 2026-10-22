<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recognition extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = ['appreciation', 'star_performer', 'innovation', 'teamwork', 'customer_service', 'leadership'];

    protected $table = 'perf_recognitions';

    protected $guarded = ['id', 'company_id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by_user_id');
    }
}
