<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDependant extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $guarded = ['id', 'employee_id'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }
}
