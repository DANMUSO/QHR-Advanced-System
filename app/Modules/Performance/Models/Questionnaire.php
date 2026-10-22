<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Questionnaire extends Model
{
    use BelongsToCompany;

    protected $table = 'perf_questionnaires';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'self_submitted_at' => 'datetime', 'supervisor_submitted_at' => 'datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'perf_questionnaire_questions', 'questionnaire_id', 'question_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(QuestionnaireResponse::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(QuestionnaireComment::class);
    }
}
