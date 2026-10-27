<?php

namespace App\Modules\Recruitment\Database;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\AssetAssignment;
use App\Modules\Recruitment\Models\InductionTask;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Posting;
use App\Modules\Recruitment\Models\Requisition;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Recruitment demo data per company. Re-runnable: skips companies that already have requisitions. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('en_US');
        $faker->seed(808);
        $surnames = ['Kamau', 'Otieno', 'Wanjiku', 'Mutua', 'Achieng', 'Kiprop', 'Njoroge', 'Wambui', 'Odhiambo', 'Chebet', 'Maina', 'Rotich', 'Ochieng', 'Wairimu'];

        foreach (Company::all() as $company) {
            if (Requisition::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
                continue;
            }
            $positions = Position::withoutGlobalScopes()->where('company_id', $company->id)->where('is_supervisor', false)->get();
            $departments = Department::withoutGlobalScopes()->where('company_id', $company->id)->get();
            if ($positions->isEmpty() || $departments->isEmpty()) {
                continue;
            }
            $staff = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->get();

            // Induction templates
            foreach ([
                ['Sign employment contract & NDA', 'hr', 0], ['Submit KRA PIN, NSSF, SHIF & bank details', 'hr', 1],
                ['Laptop, email & HR portal access', 'it', 0], ['Set up two-factor authentication', 'it', 1],
                ['Payroll enrolment & pay profile', 'finance', 3], ['Meet your team & supervisor', 'manager', 1],
                ['Agree probation objectives', 'manager', 14], ['Building access card & safety briefing', 'security', 0],
                ['Read Code of Conduct & HR policies', 'hr', 7],
            ] as [$name, $cat, $days]) {
                $t = new InductionTask(['name' => $name, 'category' => $cat, 'due_days' => $days, 'is_mandatory' => $cat !== 'manager' || $days < 7, 'owner_role' => $cat]);
                $t->company_id = $company->id;
                $t->save();
            }

            $n = 1;
            foreach ($positions->random(min(5, $positions->count())) as $i => $position) {
                $status = ['approved', 'approved', 'approved', 'pending_approval', 'filled'][$i % 5];
                // Positions carry no department: use the department where that position's staff work.
                $deptId = $staff->where('position_id', $position->id)->countBy('department_id')->sortDesc()->keys()->first() ?? $departments->random()->id;
                $vacancies = rand(1, 3);
                // Hires never exceed vacancies: a filled requisition has all of them hired, an open one fewer.
                $hiresLeft = $status === 'filled' ? $vacancies : $vacancies - 1;
                $req = new Requisition([
                    'position_id' => $position->id, 'department_id' => $deptId,
                    'vacancies' => $vacancies, 'type' => $faker->randomElement(['permanent', 'permanent', 'fixed_term', 'internship']),
                    'justification' => $faker->randomElement(['Replacement for a resigned staff member.', 'Business growth in the region.', 'New product line launch.', 'Workload increase after branch expansion.']),
                    'status' => $status, 'approved_at' => $status !== 'pending_approval' ? now()->subDays(rand(20, 60)) : null,
                ]);
                $req->company_id = $company->id;
                $req->requisition_number = sprintf('REQ-%d-%04d', $company->id, $n++);
                $req->save();

                if ($status === 'pending_approval') {
                    continue;
                }
                $posting = new Posting([
                    'requisition_id' => $req->id, 'title' => $position->title, 'department_id' => $req->department_id,
                    'location' => $faker->randomElement(['Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Remote / Hybrid']),
                    'closing_date' => now()->addDays(rand(-10, 30)), 'status' => $status === 'filled' ? 'closed' : 'published',
                    'description' => "We are looking for a motivated {$position->title} to join our growing team.",
                    'requirements' => "- Relevant degree or diploma\n- 2+ years' experience\n- Strong communication skills",
                    'slug' => Str::slug($position->title).'-'.Str::lower(Str::random(6)),
                ]);
                $posting->company_id = $company->id;
                $posting->save();

                for ($a = 0, $count = rand(6, 14); $a < $count; $a++) {
                    $first = $faker->firstName();
                    $last = $faker->randomElement($surnames);
                    $stage = $faker->randomElement(['applied', 'applied', 'screening', 'screening', 'interview', 'interview', 'offer', 'rejected', 'rejected', 'hired']);
                    if ($status === 'filled' && $a < $vacancies) {
                        $stage = 'hired';
                    }
                    if ($stage === 'hired' && $hiresLeft-- <= 0) {
                        $stage = 'rejected';
                    }
                    $applied = now()->subDays(rand(5, 45));
                    $applicant = new Applicant([
                        'posting_id' => $posting->id, 'name' => "$first $last", 'email' => strtolower("$first.$last".rand(1, 99).'@gmail.com'),
                        'phone' => '07'.rand(10000000, 99999999), 'stage' => $stage, 'score' => in_array($stage, ['applied']) ? null : rand(45, 95),
                        'source' => $faker->randomElement(['LinkedIn', 'BrighterMonday', 'Referral', 'Company website', 'Fuzu']),
                        'applied_at' => $applied, 'hired_at' => $stage === 'hired' ? $applied->copy()->addDays(rand(14, 35)) : null,
                    ]);
                    $applicant->company_id = $company->id;
                    $applicant->save();

                    if (in_array($stage, ['interview', 'offer', 'hired'], true)) {
                        $iv = new Interview([
                            'applicant_id' => $applicant->id, 'interview_type' => $faker->randomElement(['phone', 'hr', 'technical', 'panel']),
                            'scheduled_at' => $stage === 'interview' ? now()->addDays(rand(1, 10))->setTime(rand(9, 15), 0) : $applied->copy()->addDays(rand(5, 12))->setTime(10, 0),
                            'interviewer_id' => $staff->random()?->id, 'location' => $faker->randomElement(['Boardroom A', 'Microsoft Teams', 'Google Meet', 'HR Office']),
                            'status' => $stage === 'interview' ? 'scheduled' : 'completed', 'score' => $stage === 'interview' ? null : rand(60, 92),
                        ]);
                        $iv->company_id = $company->id;
                        $iv->save();
                    }
                    if (in_array($stage, ['offer', 'hired'], true)) {
                        $offer = new Offer([
                            'applicant_id' => $applicant->id, 'offered_salary' => rand(45, 180) * 1000,
                            'start_date' => now()->addDays(rand(7, 30)), 'expiry_date' => now()->addDays(rand(3, 14)),
                            'status' => $stage === 'hired' ? 'accepted' : 'sent',
                        ]);
                        $offer->company_id = $company->id;
                        $offer->save();
                    }
                }
            }

            // Assets for a sample of staff
            foreach ($staff->random(min(15, $staff->count())) as $emp) {
                foreach ($faker->randomElements([['Dell Latitude 5440', 'LT'], ['iPhone 13', 'PH'], ['Safaricom MiFi', 'MF'], ['Access card', 'AC'], ['HP ProBook 450', 'LT']], rand(1, 2)) as [$name, $prefix]) {
                    $returned = rand(1, 8) === 1;
                    $asset = new AssetAssignment([
                        'employee_id' => $emp->id, 'asset_name' => $name, 'asset_tag' => $prefix.'-'.rand(10000, 99999),
                        'assigned_date' => now()->subDays(rand(30, 700)), 'return_date' => $returned ? now()->subDays(rand(1, 20)) : null,
                        'status' => $returned ? 'returned' : 'assigned',
                    ]);
                    $asset->company_id = $company->id;
                    $asset->save();
                }
            }
        }
    }
}
