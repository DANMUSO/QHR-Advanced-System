<?php

namespace Database\Seeders;

use App\Models\Benefit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\Position;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Grows the demo workforce: a third company plus extra staff in the existing ones,
 * and fills family, qualification and benefit records for every employee. Safe to re-run.
 */
class ExpandedPeopleSeeder extends Seeder
{
    private \Faker\Generator $faker;

    private const SURNAMES = ['Kamau', 'Otieno', 'Wanjiku', 'Mutua', 'Achieng', 'Kiprop', 'Njoroge', 'Wambui', 'Odhiambo', 'Chebet',
        'Mwangi', 'Kariuki', 'Omondi', 'Nyambura', 'Kilonzo', 'Barasa', 'Wekesa', 'Atieno', 'Maina', 'Rotich', 'Kosgei', 'Muthoni',
        'Ochieng', 'Wairimu', 'Kibet', 'Nduta', 'Onyango', 'Jeptoo', 'Mbugua', 'Akinyi', 'Ngugi', 'Cherono', 'Owino', 'Wangari'];

    public function run(): void
    {
        $this->faker = Faker::create('en_US');
        $this->faker->seed(4242);

        $cemes = Company::where('code', 'CEMES')->first() ?? $this->createCemes();

        $targets = ['IDEON' => 70, 'MCH' => 45, 'CEMES' => 50];
        foreach ($targets as $code => $target) {
            $company = Company::where('code', $code)->first();
            if (! $company) {
                continue;
            }
            $current = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->count();
            for ($i = $current; $i < $target; $i++) {
                $this->makeEmployee($company);
            }
        }

        $this->fillFamilyAndQualifications();
        $this->command?->info('People: '.Employee::withoutGlobalScopes()->count().' employees across '.Company::count().' companies.');
    }

    private function createCemes(): Company
    {
        $company = Company::create([
            'name' => 'Cemes Limited', 'code' => 'CEMES', 'email' => 'info@cemes.co.ke', 'phone' => '+254711000000',
            'location' => 'Nairobi', 'address' => 'P.O. Box 4500-00100 Nairobi', 'tax_pin' => 'P051234567C',
            'incorporation_date' => '2015-06-15', 'brand_color' => '#0d9488',
            'settings' => ['medical_annual_limit' => 90000, 'medical_monthly_limit' => 8000],
        ]);

        foreach (['Head Office' => 'Kilimani, Nairobi', 'Thika' => 'Thika Town', 'Eldoret' => 'Eldoret CBD'] as $name => $loc) {
            Branch::create(['company_id' => $company->id, 'name' => $name, 'location' => $loc, 'email' => str($name)->slug().'@cemes.co.ke']);
        }
        $depts = collect(['Executive', 'Human Resources', 'Finance', 'Operations', 'Sales & Marketing', 'ICT', 'Insurance Services'])
            ->mapWithKeys(fn ($d) => [$d => Department::create(['company_id' => $company->id, 'name' => $d])]);
        $levels = collect([['Junior', 6], ['Mid-level', 6], ['Senior', 3], ['Management', 3]])
            ->mapWithKeys(fn ($l) => [$l[0] => EmploymentLevel::create(['company_id' => $company->id, 'name' => $l[0], 'probation_months' => $l[1], 'probation_review_days' => 30])]);

        foreach ([
            ['Managing Director', 'Executive', 'Management', true], ['HR Manager', 'Human Resources', 'Management', true],
            ['HR Assistant', 'Human Resources', 'Junior', false], ['Finance Manager', 'Finance', 'Management', true],
            ['Accounts Assistant', 'Finance', 'Junior', false], ['Operations Manager', 'Operations', 'Management', true],
            ['Field Officer', 'Operations', 'Junior', false], ['Sales Manager', 'Sales & Marketing', 'Senior', true],
            ['Sales Agent', 'Sales & Marketing', 'Junior', false], ['ICT Officer', 'ICT', 'Mid-level', false],
            ['Underwriting Manager', 'Insurance Services', 'Senior', true], ['Underwriter', 'Insurance Services', 'Mid-level', false],
            ['Claims Officer', 'Insurance Services', 'Mid-level', false],
        ] as [$title, $dept, $level, $sup]) {
            Position::create(['company_id' => $company->id, 'title' => $title, 'is_supervisor' => $sup, 'employment_level_id' => $levels[$level]->id]);
        }
        foreach (['Medical Cover', 'Group Life Insurance', 'Pension Scheme', 'Staff Loan Scheme'] as $b) {
            Benefit::create(['company_id' => $company->id, 'name' => $b]);
        }

        // Leadership first so new staff have supervisors.
        $positions = Position::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $md = $this->makeEmployee($company, $positions->firstWhere('title', 'Managing Director'), now()->subYears(8));
        foreach ($positions->where('is_supervisor', true)->where('title', '!=', 'Managing Director') as $p) {
            $deptName = ['HR Manager' => 'Human Resources', 'Finance Manager' => 'Finance', 'Operations Manager' => 'Operations',
                'Sales Manager' => 'Sales & Marketing', 'Underwriting Manager' => 'Insurance Services'][$p->title] ?? 'Operations';
            $mgr = $this->makeEmployee($company, $p, now()->subMonths(rand(20, 80)), $md, $depts[$deptName]);
            $depts[$deptName]->update(['head_employee_id' => $mgr->id]);
        }

        return $company;
    }

    private function makeEmployee(Company $company, ?Position $position = null, $joined = null, ?Employee $supervisor = null, ?Department $department = null): Employee
    {
        $positions = Position::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $position ??= $positions->where('is_supervisor', false)->random();
        $branch = Branch::withoutGlobalScopes()->where('company_id', $company->id)->inRandomOrder()->first();
        $department ??= $this->departmentFor($company, $position);
        $supervisor ??= Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
            ->where('department_id', $department?->id)->whereHas('position', fn ($q) => $q->withoutGlobalScopes()->where('is_supervisor', true))->first()
            ?? Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
                ->whereHas('position', fn ($q) => $q->withoutGlobalScopes()->where('title', 'Managing Director'))->first();

        $gender = $this->faker->randomElement(['male', 'female']);
        $first = $this->faker->firstName($gender);
        $last = $this->faker->randomElement(self::SURNAMES);
        $joined ??= now()->subDays(rand(5, 365 * 6));
        $level = EmploymentLevel::withoutGlobalScopes()->find($position->employment_level_id);
        $probation = $level?->probation_months ?? 6;
        $contract = $this->faker->randomElement(['permanent', 'permanent', 'permanent', 'permanent', 'contract', 'casual', 'intern']);
        $email = strtolower($first.'.'.$last.rand(100, 999)).'@'.strtolower($company->code).'.co.ke';
        $count = Employee::withoutGlobalScopes()->withTrashed()->where('company_id', $company->id)->count();

        $e = new Employee([
            'employee_number' => $company->code.'-'.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT),
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '07'.rand(10000000, 99999999),
            'gender' => $gender, 'date_of_birth' => now()->subYears(rand(21, 59))->subDays(rand(0, 364)),
            'marital_status' => $this->faker->randomElement(['single', 'married', 'married', 'married', 'divorced', 'widowed']),
            'postal_address' => 'P.O. Box '.rand(100, 99999).'-00100 Nairobi',
            'residence' => $this->faker->randomElement(['Kilimani', 'Ruaka', 'Kasarani', 'Rongai', 'Syokimau', 'Kileleshwa', 'Embakasi', 'Thika Road', 'Kitengela', 'Westlands', 'Lavington', 'Donholm']),
            'about' => $this->faker->boolean(40) ? $this->faker->sentence(14) : null,
            'national_id' => (string) rand(20000000, 39999999), 'kra_pin' => 'A0'.rand(10000000, 99999999).strtoupper($this->faker->randomLetter()),
            'nssf_number' => (string) rand(100000000, 999999999), 'shif_number' => 'SHA'.rand(1000000, 9999999),
            'helb_number' => $this->faker->boolean(25) ? 'HELB'.rand(100000, 999999) : null,
            'branch_id' => $branch?->id, 'department_id' => $department?->id, 'position_id' => $position->id,
            'employment_level_id' => $position->employment_level_id, 'primary_supervisor_id' => $supervisor?->id,
            'date_of_joining' => $joined, 'contract_type' => $contract,
            'contract_end_date' => $contract === 'permanent' ? null : now()->addDays(rand(-20, 400)),
            'probation_months' => $probation, 'probation_end_date' => $joined->copy()->addMonths($probation),
            'probation_status' => $joined->copy()->addMonths($probation)->isFuture() ? 'on_probation' : 'confirmed',
        ]);
        $e->company_id = $company->id;
        $e->save();

        $e->nextOfKin()->create(['name' => $this->faker->name(), 'relationship' => $this->faker->randomElement(['mother', 'father', 'sister', 'brother', 'guardian']), 'phone' => '07'.rand(10000000, 99999999)]);

        if (rand(1, 10) > 1) {
            $user = User::create([
                'company_id' => $company->id, 'name' => $e->full_name, 'email' => $email, 'password' => DemoSeeder::PASSWORD,
                'activated_at' => rand(1, 12) > 1 ? now()->subDays(rand(1, 300)) : null, 'password_changed_at' => now()->subDays(rand(1, 200)),
                'last_login_at' => now()->subHours(rand(1, 24 * 20)),
            ]);
            $user->assignRole($position->is_supervisor ? ['employee', 'supervisor'] : ['employee']);
            $e->forceFill(['user_id' => $user->id])->save();
        }

        return $e;
    }

    private function departmentFor(Company $company, Position $position): ?Department
    {
        $map = ['HR' => 'Human Resources', 'Account' => 'Finance', 'Finance' => 'Finance', 'Payroll' => 'Finance', 'Operation' => 'Operations',
            'Field' => 'Operations', 'Sales' => 'Sales & Marketing', 'ICT' => 'ICT', 'Systems' => 'ICT', 'Credit' => 'Credit',
            'Customer' => 'Customer Service', 'Underwrit' => 'Insurance Services', 'Claims' => 'Insurance Services'];
        foreach ($map as $needle => $dept) {
            if (str_contains($position->title, $needle)) {
                $d = Department::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $dept)->first();
                if ($d) {
                    return $d;
                }
            }
        }

        return Department::withoutGlobalScopes()->where('company_id', $company->id)->inRandomOrder()->first();
    }

    /** Dependants, qualifications, a second next of kin and benefits for every employee missing them. */
    private function fillFamilyAndQualifications(): void
    {
        $levels = [
            ['Degree', ['BCom (Finance)', 'BA Economics', 'BSc Computer Science', 'BBA (HR)', 'BSc Actuarial Science', 'BEd Arts', 'LLB'], ['University of Nairobi', 'Kenyatta University', 'Strathmore University', 'Moi University', 'JKUAT', 'USIU-Africa', 'Egerton University'], ['First Class', 'Second Upper', 'Second Lower', 'Pass']],
            ['Diploma', ['Diploma in Business Management', 'Diploma in ICT', 'Diploma in Accounting', 'Diploma in Sales & Marketing'], ['KCA University', 'Kenya Polytechnic', 'Mount Kenya University', 'KIM'], ['Distinction', 'Credit', 'Pass']],
            ['Professional', ['CPA (K)', 'CHRP', 'ACCA', 'CIFA', 'ACII', 'CISA'], ['KASNEB', 'IHRM', 'ACCA', 'CII'], ['Certified', 'Part III', 'Section 4']],
            ['Masters', ['MBA', 'MSc Finance', 'MA HRM'], ['Strathmore University', 'University of Nairobi', 'USIU-Africa'], ['Distinction', 'Merit', 'Pass']],
            ['Certificate', ['KCSE', 'Certificate in Customer Service', 'Certificate in Microfinance'], ['Alliance High School', 'Moi Forces Academy', 'Kenya High', 'KCA University'], ['A', 'B+', 'B', 'C+']],
        ];

        Employee::withoutGlobalScopes()->with('company')->chunkById(100, function ($employees) use ($levels) {
            foreach ($employees as $e) {
                if (! DB::table('employee_dependants')->where('employee_id', $e->id)->exists()) {
                    $rows = [];
                    if ($e->marital_status === 'married') {
                        $rows[] = ['name' => $this->faker->name($e->gender === 'male' ? 'female' : 'male'), 'relationship' => 'spouse',
                            'date_of_birth' => now()->subYears(rand(24, 55))->toDateString(), 'phone' => '07'.rand(10000000, 99999999)];
                    }
                    for ($c = 0, $n = $e->marital_status === 'single' ? rand(0, 1) : rand(0, 4); $c < $n; $c++) {
                        $rows[] = ['name' => $this->faker->firstName().' '.$e->last_name, 'relationship' => 'child',
                            'date_of_birth' => now()->subYears(rand(1, 20))->subDays(rand(0, 364))->toDateString(), 'phone' => null];
                    }
                    foreach ($rows as $r) {
                        DB::table('employee_dependants')->insert($r + ['employee_id' => $e->id, 'email' => null, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }

                if (! DB::table('employee_qualifications')->where('employee_id', $e->id)->exists()) {
                    foreach ($this->faker->randomElements($levels, rand(1, 3)) as [$level, $titles, $institutions, $grades]) {
                        DB::table('employee_qualifications')->insert([
                            'employee_id' => $e->id, 'level' => $level, 'title' => $this->faker->randomElement($titles),
                            'institution' => $this->faker->randomElement($institutions), 'grade' => $this->faker->randomElement($grades),
                            'year_completed' => rand(1990, 2025), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }

                if (DB::table('employee_next_of_kin')->where('employee_id', $e->id)->count() < 2) {
                    DB::table('employee_next_of_kin')->insert([
                        'employee_id' => $e->id, 'name' => $this->faker->name(), 'relationship' => $this->faker->randomElement(['brother', 'sister', 'friend', 'relative']),
                        'phone' => '07'.rand(10000000, 99999999), 'email' => $this->faker->safeEmail(), 'address' => $this->faker->city(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                if (! DB::table('benefit_employee')->where('employee_id', $e->id)->exists()) {
                    $benefits = Benefit::withoutGlobalScopes()->where('company_id', $e->company_id)->pluck('id');
                    foreach ($benefits->random(min($benefits->count(), rand(2, 4))) as $bid) {
                        DB::table('benefit_employee')->insertOrIgnore(['benefit_id' => $bid, 'employee_id' => $e->id]);
                    }
                }
            }
        });
    }
}
