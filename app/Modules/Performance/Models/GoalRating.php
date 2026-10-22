<?php

namespace App\Modules\Performance\Models;

use Illuminate\Database\Eloquent\Model;

class GoalRating extends Model
{
    protected $table = 'perf_goal_ratings';

    protected $guarded = ['id'];

    public $timestamps = false;
}
