<?php

use App\Models\Employee;
use App\Modules\Engagement\Models\EngagementComment;

return [
    'name' => 'Engagement',
    'order' => 80,

    'boot' => function () {
        Employee::resolveRelationUsing('engagementComments', fn (Employee $e) => $e->hasMany(EngagementComment::class));
    },
];
