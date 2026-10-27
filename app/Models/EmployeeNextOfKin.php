<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeNextOfKin extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $table = 'employee_next_of_kin';

    protected $guarded = ['id', 'employee_id'];

    public const RELATIONSHIPS = ['spouse', 'mother', 'father', 'brother', 'sister', 'son', 'daughter', 'guardian', 'relative', 'friend'];
}
