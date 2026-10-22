<?php

namespace App\Modules\Engagement\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementReply extends Model
{
    use BelongsToCompany;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seen' => 'boolean', 'from_author' => 'boolean'];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(EngagementComment::class, 'comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
