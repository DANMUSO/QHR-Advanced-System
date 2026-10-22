<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\Frequency;
use App\Modules\Performance\Models\Question;
use App\Modules\Performance\Models\QuestionCategory;
use App\Modules\Performance\Models\RatingBand;
use App\Modules\Performance\Models\RatingScale;
use App\Modules\Performance\Models\Unit;

/** Seeds each company's performance defaults (units, frequencies, rating scale, bands, question bank) once. */
class SetupService
{
    public const UNITS = [['KES', false], ['%', true], ['People', false], ['Unit', false], ['Count', false]];

    public const FREQUENCIES = [['Daily', 1], ['Weekly', 7], ['Monthly', 30], ['Quarterly', 90], ['Semi-annual', 180], ['Annual', 365]];

    public const SCALE = [
        1 => ['Unsatisfactory', 'Performance is well below expectations'],
        2 => ['Needs Improvement', 'Performance partially meets expectations'],
        3 => ['Meets Expectations', 'Performance consistently meets expectations'],
        4 => ['Exceeds Expectations', 'Performance frequently exceeds expectations'],
        5 => ['Outstanding', 'Performance consistently exceeds all expectations'],
    ];

    public const BANDS = [
        ['Outstanding', 90, 'green'], ['Exceeds Expectations', 75, 'sky'], ['Meets Expectations', 60, 'brand'],
        ['Below Expectations', 40, 'amber'], ['Unsatisfactory', 0, 'red'],
    ];

    /** Category => [Sub-category => [questions]] */
    public const QUESTION_BANK = [
        'Leadership' => [
            'Vision & Direction' => [
                'Communicates a clear vision and direction to the team.',
                'Makes timely, well-reasoned decisions.',
            ],
            'Integrity' => [
                'Acts with honesty and holds themselves accountable.',
                'Leads by example and models company values.',
            ],
        ],
        'Management' => [
            'Planning & Organisation' => [
                'Plans work effectively and meets deadlines.',
                'Allocates resources and delegates appropriately.',
            ],
            'Results Orientation' => [
                'Sets measurable goals and tracks progress against them.',
            ],
        ],
        'Work Environment' => [
            'Collaboration' => [
                'Works constructively with colleagues across departments.',
                'Contributes to a positive and respectful workplace.',
            ],
            'Safety & Compliance' => [
                'Follows company policies, procedures and safety rules.',
            ],
        ],
        'People Support' => [
            'Coaching & Development' => [
                'Gives regular, constructive feedback.',
                'Supports the growth and development of others.',
            ],
            'Customer Focus' => [
                'Responds to internal and external customers promptly and courteously.',
            ],
        ],
        'Business Planning' => [
            'Strategic Thinking' => [
                'Understands how their work contributes to business goals.',
                'Identifies opportunities for improvement and innovation.',
            ],
            'Financial Awareness' => [
                'Manages costs and budgets responsibly.',
            ],
        ],
    ];

    public static function ensureDefaults(int $companyId): void
    {
        if (! Unit::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            foreach (self::UNITS as [$name, $pct]) {
                Unit::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'name' => $name], ['is_percentage' => $pct]);
            }
        }
        if (! Frequency::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            foreach (self::FREQUENCIES as [$name, $days]) {
                Frequency::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'name' => $name], ['days' => $days]);
            }
        }
        if (! RatingScale::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            foreach (self::SCALE as $value => [$label, $description]) {
                RatingScale::withoutGlobalScopes()->create(['company_id' => $companyId, 'value' => $value, 'label' => $label, 'description' => $description]);
            }
        }
        if (! RatingBand::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            foreach (self::BANDS as [$name, $min, $color]) {
                RatingBand::withoutGlobalScopes()->create(['company_id' => $companyId, 'name' => $name, 'min_score' => $min, 'color' => $color]);
            }
        }
        if (! QuestionCategory::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            self::seedQuestionBank($companyId);
        }
    }

    public static function seedQuestionBank(int $companyId): void
    {
        $sort = 0;
        foreach (self::QUESTION_BANK as $category => $subs) {
            $cat = self::category($companyId, null, $category, $sort++);
            $subSort = 0;
            foreach ($subs as $subName => $questions) {
                $sub = self::category($companyId, $cat->id, $subName, $subSort++);
                foreach ($questions as $text) {
                    $q = new Question(['category_id' => $sub->id, 'question' => $text, 'is_active' => true]);
                    $q->company_id = $companyId;
                    Question::withoutGlobalScopes()->where('company_id', $companyId)->where('category_id', $sub->id)
                        ->where('question', $text)->exists() || $q->save();
                }
            }
        }
    }

    /** company_id is guarded, so it is force-filled (console / seeding has no tenant to stamp). */
    private static function category(int $companyId, ?int $parentId, string $name, int $sort): QuestionCategory
    {
        $category = QuestionCategory::withoutGlobalScopes()->firstOrNew(['company_id' => $companyId, 'parent_id' => $parentId, 'name' => $name]);
        if (! $category->exists) {
            $category->forceFill(['company_id' => $companyId, 'sort' => $sort])->save();
        }

        return $category;
    }

    public static function maxScale(int $companyId): int
    {
        return (int) (RatingScale::withoutGlobalScopes()->where('company_id', $companyId)->max('value') ?: 5);
    }

    /** @return array<string, float> name => min score */
    public static function bands(int $companyId): array
    {
        $bands = RatingBand::withoutGlobalScopes()->where('company_id', $companyId)->pluck('min_score', 'name')
            ->map(fn ($v) => (float) $v)->all();

        return $bands ?: ScoreCalculator::DEFAULT_BANDS;
    }
}
