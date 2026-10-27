<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeQualification extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $guarded = ['id', 'employee_id'];
}
