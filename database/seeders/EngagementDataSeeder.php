<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Engagement\Models\EngagementComment;
use App\Modules\Engagement\Models\EngagementReply;
use Illuminate\Database\Seeder;

class EngagementDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $employees = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->get();

            if ($employees->isEmpty()) return;

            $categories = array_keys(EngagementComment::CATEGORIES);

            $suggestions = [
                'We should have a monthly team lunch to improve morale and collaboration.',
                'The office kitchen needs better equipment. A microwave and coffee machine would be great.',
                'Can we get standing desks? Sitting all day is affecting productivity.',
                'I suggest we implement flexible working hours to help with work-life balance.',
                'The internet connection in the Mombasa branch is very slow. Please upgrade it.',
                'Training opportunities should be communicated earlier so we can plan.',
                'The leave application process takes too long. Can we streamline it?',
                'Parking space is insufficient. Can we negotiate with the building management?',
                'Would love to see a mentorship programme for junior staff.',
                'The performance review criteria should be shared at the start of the year.',
                'Can we have a wellness programme? Gym membership subsidy would be appreciated.',
                'The printer on the 3rd floor has been broken for 2 weeks. Please fix it.',
            ];

            $hrUser = User::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'hr-admin'))
                ->first()
                ?? User::withoutGlobalScopes()->where('company_id', $company->id)->first();

            foreach ($suggestions as $body) {
                $author  = $employees->random();
                $anon    = (bool) rand(0, 1);
                $hasReply = (bool) rand(0, 1);

                $comment = EngagementComment::withoutGlobalScopes()->create([
                    'company_id'  => $company->id,
                    'employee_id' => $anon ? null : $author->id,
                    'category'    => $categories[array_rand($categories)],
                    'body'        => $body,
                    'anonymous'   => $anon,
                    'status'      => $hasReply ? 'replied' : 'pending',
                    'created_at'  => now()->subDays(rand(1, 90)),
                    'updated_at'  => now()->subDays(rand(0, 30)),
                ]);

                if ($hasReply && $hrUser) {
                    EngagementReply::withoutGlobalScopes()->create([
                        'company_id' => $company->id,
                        'comment_id' => $comment->id,
                        'user_id'    => $hrUser->id,
                        'body'       => collect([
                            'Thank you for your suggestion. We will look into this.',
                            'This has been noted and escalated to management.',
                            'We appreciate your feedback. Action has been taken.',
                            'This is being reviewed by the relevant department.',
                            'Great idea! We will include this in our next planning meeting.',
                        ])->random(),
                        'seen'       => (bool) rand(0, 1),
                        'created_at' => $comment->created_at->copy()->addDays(rand(1, 7)),
                    ]);
                }
            }
        });
    }
}
