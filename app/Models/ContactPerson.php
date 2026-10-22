<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ContactPerson extends Model
{
    use BelongsToCompany;

    protected $table = 'contact_persons';

    protected $guarded = ['id'];
}
