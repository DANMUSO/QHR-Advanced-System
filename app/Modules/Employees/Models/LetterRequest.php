<?php

namespace App\Modules\Employees\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee's request for a letter; HR issues it with the letter generator (LetterService). */
class LetterRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'employee_letter_requests';

    protected $guarded = ['id', 'company_id'];

    /** Templates staff may request themselves. */
    public const TEMPLATES = ['introduction', 'employment_confirmation', 'no_objection'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(EmployeeLetter::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
