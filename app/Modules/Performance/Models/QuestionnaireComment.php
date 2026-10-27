<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionnaireComment extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $table = 'perf_questionnaire_comments';

    protected $guarded = ['id'];

}
