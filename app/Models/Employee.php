<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
// Cross-module relations registered by each module's boot() via resolveRelationUsing():
// Leave:       leaveBalances, leaveRequests
// Payroll:     payProfile, employeeComponents, payslips, loans, salaryHistory, salaryAdvances
// Performance: goals, appraisals, enrollments, coachingSessions, pips, recognitions,
//              talentPools, discussionPoints, certifications, skills, potentialRatings
// Probation:   probationReview
// Engagement:  engagementComments
// Documents:   documents
// Attendance:  attendanceDays, attendancePunches, overtimes, timesheets
// Employees:   contracts, moves, kycChecks, exitRecord, successorRecord
// Claims:      medicalClaims, claimLimit

class Employee extends Model
{
    use BelongsToCompany, HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = ['id', 'company_id', 'user_id', 'status', 'previous_employee_id'];

    protected $appends = ['full_name', 'photo_url'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_joining' => 'date',
            'contract_end_date' => 'date',
            'probation_end_date' => 'date',
            'has_medical_cover' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }

        return $q->where(function ($w) use ($term) {
            $like = '%'.$term.'%';
            $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('employee_number', 'like', $like)
                ->orWhere('phone', 'like', $like)->orWhereRaw("CONCAT(first_name,' ',last_name) like ?", [$like]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(EmploymentLevel::class, 'employment_level_id');
    }

    public function primarySupervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'primary_supervisor_id');
    }

    public function secondarySupervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'secondary_supervisor_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'primary_supervisor_id');
    }

    public function nextOfKin(): HasMany
    {
        return $this->hasMany(EmployeeNextOfKin::class);
    }

    public function dependants(): HasMany
    {
        return $this->hasMany(EmployeeDependant::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(EmployeeQualification::class);
    }

    public function benefits(): BelongsToMany
    {
        return $this->belongsToMany(Benefit::class);
    }

    public function previousRecord(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'previous_employee_id')->withoutGlobalScope('company');
    }

    public function isSupervisor(): bool
    {
        return ($this->position?->is_supervisor ?? false) || $this->directReports()->exists();
    }

    /** Percentage of key profile fields filled in, for the dashboard nudge. */
    public function profileCompleteness(): int
    {
        $fields = ['phone', 'email', 'date_of_birth', 'gender', 'marital_status', 'residence', 'photo_path',
            'national_id', 'kra_pin', 'nssf_number', 'shif_number'];
        $filled = collect($fields)->filter(fn ($f) => filled($this->{$f}))->count();
        $filled += $this->nextOfKin()->exists() ? 1 : 0;

        return (int) round($filled / (count($fields) + 1) * 100);
    }
}
