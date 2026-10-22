<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends RecruitmentModel
{
    use SoftDeletes;

    protected $table = 'recruitment_requisitions';

    public const TYPES = ['permanent', 'fixed_term', 'casual', 'internship'];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'filled'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Who approved or rejected it. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
