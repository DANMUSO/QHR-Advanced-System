<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionnaireResponse extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $table = 'perf_questionnaire_responses';

    protected $guarded = ['id'];

}
