<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

class GoalReminder extends Model
{
    protected $table = 'perf_goal_reminders';

    protected $guarded = ['id'];

    public $timestamps = false;
}
