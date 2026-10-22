<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeExit extends Model
{
    use BelongsToCompany;
    public const REASONS = [
        'end_of_contract' => 'End of Contract',
        'resignation' => 'Resignation',
        'termination' => 'Termination',
        'death' => 'Death',
        'medical_grounds' => 'Medical Grounds',
        'retrenchment' => 'Retrenchment',
        'company_closure' => 'Company Closure',
        'absconding' => 'Absconding',
        'redundancy' => 'Redundancy',
        'retirement' => 'Retirement',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['exit_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
