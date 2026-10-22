<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_supervisor' => 'boolean'];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(EmploymentLevel::class, 'employment_level_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
