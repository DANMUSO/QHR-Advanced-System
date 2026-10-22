<?php

namespace App\Modules\Engagement\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngagementComment extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = [
        'supervision' => 'Supervision / Management',
        'environment' => 'Work Environment',
        'tools' => 'System / Tools',
        'satisfaction' => 'Work Satisfaction',
        'career' => 'Career Opportunities',
        'other' => 'Other',
    ];

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['author_hash'];

    protected function casts(): array
    {
        return ['anonymous' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(EngagementReply::class, 'comment_id');
    }
}
