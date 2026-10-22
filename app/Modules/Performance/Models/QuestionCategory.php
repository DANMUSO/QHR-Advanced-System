<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionCategory extends Model
{
    use BelongsToCompany;

    protected $table = 'perf_question_categories';

    protected $guarded = ['id', 'company_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'category_id')->orderBy('id');
    }

    /** Top-level category id (a sub-category resolves to its parent). */
    public function rootId(): int
    {
        return $this->parent_id ?? $this->id;
    }
}
