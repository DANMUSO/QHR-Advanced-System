<?php

namespace App\Modules\Recruitment\Models;

class InductionTask extends RecruitmentModel
{
    protected $table = 'recruitment_induction_tasks';

    public const CATEGORIES = ['hr', 'it', 'finance', 'manager', 'security'];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean'];
    }
}
