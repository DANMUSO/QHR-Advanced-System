<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    use BelongsToCompany;

    public const GROUPS = ['company', 'personal', 'academic', 'professional', 'office'];

    /** Seeded for every company: [name, group, requires_expiry, is_required]. */
    public const DEFAULTS = [
        ['Contracts', 'company', true, false],
        ['Onboarding Forms', 'company', false, true],
        ['ID/Passport', 'personal', false, true],
        ['NSSF', 'personal', false, true],
        ['NHIF/SHIF', 'personal', false, true],
        ['KRA PIN', 'personal', false, true],
        ['High School', 'academic', false, false],
        ['College/University', 'academic', false, false],
        ['Professional Certificates', 'professional', false, false],
        ['CV', 'professional', false, true],
        ['Letters of Recommendation', 'professional', false, false],
        ['Letters of Service', 'professional', false, false],
        ['Internships', 'professional', false, false],
        ['Employment Contracts', 'office', true, true],
        ['Promotion Letters', 'office', false, false],
        ['Performance Appraisal Forms', 'office', false, false],
        ['Training Recommendation Letters', 'office', false, false],
        ['Others', 'personal', false, false],
    ];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'requires_expiry' => 'boolean',
            'is_required' => 'boolean',
            'allow_self_upload' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** Creates any missing default types for a company (idempotent). */
    public static function seedDefaults(int $companyId): void
    {
        foreach (self::DEFAULTS as $i => [$name, $group, $expiry, $required]) {
            $type = static::withoutGlobalScope('company')->firstOrNew(['company_id' => $companyId, 'name' => $name]);
            if (! $type->exists) {
                $type->forceFill(['group' => $group, 'requires_expiry' => $expiry, 'is_required' => $required, 'sort_order' => $i + 1])->save();
            }
        }
    }
}
