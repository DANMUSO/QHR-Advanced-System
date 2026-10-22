<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    use BelongsToCompany;

    protected $table = 'ld_skills';

    protected $guarded = ['id', 'company_id'];

    public function levels(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }
}
