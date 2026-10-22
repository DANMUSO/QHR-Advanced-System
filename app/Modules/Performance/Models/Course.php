<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use BelongsToCompany;

    public const DELIVERY = ['classroom', 'online', 'blended'];

    protected $table = 'ld_courses';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['duration_hours' => 'float', 'cost' => 'float', 'is_active' => 'boolean'];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
