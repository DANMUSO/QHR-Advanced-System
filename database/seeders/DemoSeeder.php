<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Benefit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\EmploymentLevel;
use App\Models\Position;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development. Every demo account uses the password "Password@123".
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password@123';

    private \Faker\Generator $faker;

    public function run(): void
    {
        $this->faker = Faker::create('en_US');
        $this->faker->seed(2026);

        // Super admin
        if (! User::where('email', 'admin@hr.test')->exists()) {
            User::create([
                'name'                => 'System Administrator',
                'email'               => 'admin@hr.test',
                'password'            => self::PASSWORD,
                'activated_at'        => now(),
                'password_changed_at' => now(),
            ])->assignRole('super-admin');
        }

        // Two realistic Kenyan companies
        if (! Company::where('code', 'SFS')->exists()) {
            $this->seedCompany(
                name:      'Savanna Financial Services Ltd',
                code:      'SFS',
                branches:  [
                    'Head Office'  => 'Upper Hill, Nairobi',
                    'Westlands'    => 'Westlands, Nairobi',
                    'Mombasa'      => 'Nyali, Mombasa',
                    'Kisumu'       => 'Milimani, Kisumu',
                ],
                headcount: 55,
                primary:   true,
            );
        }

        if (! Company::where('code', 'RVA')->exists()) {
            $this->seedCompany(
                name:      'Rift Valley Agribusiness Co.',
                code:      'RVA',
                branches:  [
                    'Nakuru HQ'    => 'Nakuru Town',
                    'Eldoret'      => 'Eldoret Town',
                ],
                headcount: 30,
                primary:   false,
            );
        }
    }

    private function seedCompany(string $name, string $code, array $branches, int $headcount, bool $primary = false): void
    {
        $company = Company::create([
            'name'               => $name,
            'code'               => $code,
            'email'              => strtolower($code) . '@example.co.ke',
            'phone'              => '+254700' . rand(100000, 999999),
            'location'           => 'Nairobi',
            'address'            => 'P.O. Box ' . rand(1000, 9999) . '-00100 Nairobi',
            'tax_pin'            => 'P05' . rand(1000000, 9999999) . 'X',
            'incorporation_date' => '2010-06-01',
            'settings'           => $primary ? null : ['medical_annual_limit' => 60000, 'medical_monthly_limit' => 5000],
        ]);

        $branchModels = collect($branches)->map(fn ($loc, $bName) => Branch::create([
            'company_id' => $company->id,
            'name'       => $bName,
            'location'   => $loc,
            'email'      => str($bName)->slug() . '@' . strtolower($code) . '.co.ke',
        ]))->values();

        $deptNames = ['Executive', 'Human Resources', 'Finance & Accounts', 'Operations', 'Sales & Marketing', 'ICT', 'Credit & Risk', 'Customer Experience'];
        $departments = collect($deptNames)->mapWithKeys(fn ($d) => [
            $d => Department::create(['company_id' => $company->id, 'name' => $d]),
        ]);

        $levels = collect([
            ['Junior Staff', 6], ['Mid-Level', 6], ['Senior Staff', 3], ['Management', 3],
        ])->mapWithKeys(fn ($l) => [
            $l[0] => EmploymentLevel::create([
                'company_id'          => $company->id,
                'name'                => $l[0],
                'probation_months'    => $l[1],
                'probation_review_days' => 30,
            ]),
        ]);

        $positions = collect([
            ['Chief Executive Officer',    'Executive',           'Management',  true],
            ['HR Manager',                 'Human Resources',     'Management',  true],
            ['HR Officer',                 'Human Resources',     'Mid-Level',   false],
            ['Finance Manager',            'Finance & Accounts',  'Management',  true],
            ['Senior Accountant',          'Finance & Accounts',  'Senior Staff',false],
            ['Payroll Officer',            'Finance & Accounts',  'Mid-Level',   false],
            ['Operations Manager',         'Operations',          'Management',  true],
            ['Operations Coordinator',     'Operations',          'Mid-Level',   false],
            ['Operations Assistant',       'Operations',          'Junior Staff',false],
            ['Sales Manager',              'Sales & Marketing',   'Management',  true],
            ['Sales Executive',            'Sales & Marketing',   'Junior Staff',false],
            ['Marketing Officer',          'Sales & Marketing',   'Mid-Level',   false],
            ['ICT Manager',                'ICT',                 'Senior Staff',true],
            ['Systems Developer',          'ICT',                 'Mid-Level',   false],
            ['IT Support Officer',         'ICT',                 'Junior Staff',false],
            ['Credit Manager',             'Credit & Risk',       'Senior Staff',true],
            ['Credit Analyst',             'Credit & Risk',       'Mid-Level',   false],
            ['Customer Experience Lead',   'Customer Experience', 'Senior Staff',true],
            ['Customer Care Agent',        'Customer Experience', 'Junior Staff',false],
        ])->mapWithKeys(fn ($p) => [$p[0] => [
            'model'      => Position::create([
                'company_id'          => $company->id,
                'title'               => $p[0],
                'is_supervisor'       => $p[3],
                'employment_level_id' => $levels[$p[2]]->id,
            ]),
            'department' => $departments[$p[1]],
            'level'      => $levels[$p[2]],
        ]]);

        foreach (['Medical Cover', 'Group Life Insurance', 'Pension Scheme', 'Airtime Allowance', 'Transport Allowance'] as $b) {
            Benefit::create(['company_id' => $company->id, 'name' => $b]);
        }

        // CEO first
        $ceo = $this->makeEmployee($company, $positions['Chief Executive Officer'], $branchModels[0], null, now()->subYears(8));

        // Department heads
        $managers = collect();
        foreach ($positions->filter(fn ($p) => $p['model']->is_supervisor && $p['model']->title !== 'Chief Executive Officer') as $title => $p) {
            $mgr = $this->makeEmployee($company, $p, $branchModels[0], $ceo, now()->subMonths(rand(24, 84)));
            $managers[$p['department']->name] = $mgr;
            $p['department']->update(['head_employee_id' => $mgr->id]);
        }

        $branchModels->each(fn ($b, $i) => $b->update([
            'head_employee_id' => $managers->values()[$i % $managers->count()]->id,
        ]));

        // Staff
        $staffPositions = $positions->reject(fn ($p) => $p['model']->is_supervisor)->values();
        $remaining = $headcount - 1 - $managers->count();
        for ($i = 0; $i < $remaining; $i++) {
            $p      = $staffPositions[$i % $staffPositions->count()];
            $joined = now()->subDays(rand(30, 365 * 5));
            $this->makeEmployee($company, $p, $branchModels->random(), $managers[$p['department']->name] ?? $ceo, $joined);
        }

        // Past leavers for turnover charts
        $reasons = array_keys(EmployeeExit::REASONS);
        for ($i = 0; $i < (int) ceil($headcount / 8); $i++) {
            $p = $staffPositions->random();
            $e = $this->makeEmployee($company, $p, $branchModels->random(), $managers[$p['department']->name] ?? $ceo, now()->subDays(rand(400, 900)), withUser: false);
            $e->forceFill(['status' => 'exited'])->save();
            EmployeeExit::create([
                'company_id'  => $company->id,
                'employee_id' => $e->id,
                'exit_date'   => now()->subDays(rand(5, 360)),
                'reason'      => $reasons[array_rand(array_slice($reasons, 0, 4))],
                'comments'    => 'Demo exit record',
            ]);
        }

        if ($primary) {
            $this->seedRoleAccounts($company, $positions, $branchModels, $managers, $ceo);
        }

        // Announcements
        Announcement::create([
            'company_id'   => $company->id,
            'title'        => 'Welcome to ' . $name . ' HR Portal',
            'body'         => 'We have launched our new HR system. Please update your profile, review your leave balances, and set up two-factor authentication from Account → Security.',
            'pinned'       => true,
            'published_at' => now()->subDays(3),
        ]);
        Announcement::create([
            'company_id'   => $company->id,
            'title'        => 'Q2 Performance Reviews Now Open',
            'body'         => 'All supervisors should agree Q2 KPIs with their teams by end of this month. Employees can view their goals under My Portal → Goals & KPIs.',
            'published_at' => now()->subDays(7),
        ]);
        Announcement::create([
            'company_id'   => $company->id,
            'title'        => 'Public Holiday Notice – Madaraka Day',
            'body'         => 'The office will be closed on 1st June in observance of Madaraka Day. Enjoy the long weekend!',
            'published_at' => now()->subDays(14),
        ]);
    }

    private function seedRoleAccounts(Company $company, $positions, $branches, $managers, Employee $ceo): void
    {
        $accounts = [
            ['hr@hr.test',       'Grace',  'Wanjiru', 'HR Manager',          ['hr-admin', 'supervisor', 'employee'], $ceo],
            ['finance@hr.test',  'Peter',  'Otieno',  'Finance Manager',     ['finance', 'supervisor', 'employee'],  $ceo],
            ['payroll@hr.test',  'Faith',  'Akinyi',  'Payroll Officer',     ['payroll-officer', 'employee'],        null],
            ['supervisor@hr.test','James', 'Mwangi',  'Operations Manager',  ['supervisor', 'employee'],             $ceo],
            ['employee@hr.test', 'Mary',   'Njeri',   'Operations Assistant',['employee'],                          null],
        ];

        foreach ($accounts as [$email, $first, $last, $title, $roles, $supervisor]) {
            $p          = $positions[$title];
            $supervisor = $supervisor ?? $managers[$p['department']->name] ?? $ceo;

            if ($email === 'employee@hr.test') {
                $sup = Employee::whereHas('user', fn ($u) => $u->where('email', 'supervisor@hr.test'))->first();
                if ($sup) $supervisor = $sup;
            }

            $e = $this->makeEmployee($company, $p, $branches[0], $supervisor, now()->subMonths(rand(14, 48)), withUser: false);
            $e->update(['first_name' => $first, 'last_name' => $last, 'email' => $email]);

            $user = User::create([
                'company_id'          => $company->id,
                'name'                => "$first $last",
                'email'               => $email,
                'password'            => self::PASSWORD,
                'activated_at'        => now(),
                'password_changed_at' => now()->subDays(rand(5, 90)),
            ]);
            $user->syncRoles($roles);
            $e->forceFill(['user_id' => $user->id])->save();
        }
    }

    private function makeEmployee(Company $company, array $p, Branch $branch, ?Employee $supervisor, $joined, bool $withUser = true): Employee
    {
        $gender = $this->faker->randomElement(['male', 'female']);
        $first  = $this->faker->firstName($gender);
        $last   = $this->faker->randomElement([
            'Kamau', 'Otieno', 'Wanjiku', 'Mutua', 'Achieng', 'Kiprop', 'Njoroge', 'Wambui',
            'Odhiambo', 'Chebet', 'Mwangi', 'Kariuki', 'Omondi', 'Nyambura', 'Kilonzo',
            'Barasa', 'Wekesa', 'Atieno', 'Kimani', 'Mugo', 'Gitau', 'Ndung\'u', 'Korir',
        ]);
        $probationMonths = $p['level']->probation_months;
        $contract        = $this->faker->randomElement(['permanent', 'permanent', 'permanent', 'contract', 'casual']);
        $email           = strtolower($first . '.' . $last . rand(1, 99)) . '@' . strtolower($company->code) . '.co.ke';

        $e = new Employee([
            'employee_number'     => $company->code . '-' . str_pad((string) (Employee::withoutGlobalScopes()->where('company_id', $company->id)->count() + 1), 4, '0', STR_PAD_LEFT),
            'first_name'          => $first,
            'last_name'           => $last,
            'email'               => $email,
            'phone'               => '07' . rand(10000000, 99999999),
            'gender'              => $gender,
            'date_of_birth'       => now()->subYears(rand(22, 58))->subDays(rand(0, 364)),
            'marital_status'      => $this->faker->randomElement(['single', 'married', 'married', 'divorced']),
            'residence'           => $this->faker->randomElement(['Kilimani', 'Ruaka', 'Kasarani', 'Rongai', 'Syokimau', 'Kileleshwa', 'Embakasi', 'Lavington', 'Karen', 'Ngong']),
            'national_id'         => (string) rand(20000000, 39999999),
            'kra_pin'             => 'A0' . rand(10000000, 99999999) . 'Z',
            'nssf_number'         => (string) rand(100000000, 999999999),
            'shif_number'         => 'SHA' . rand(1000000, 9999999),
            'branch_id'           => $branch->id,
            'department_id'       => $p['department']->id,
            'position_id'         => $p['model']->id,
            'employment_level_id' => $p['level']->id,
            'primary_supervisor_id' => $supervisor?->id,
            'date_of_joining'     => $joined,
            'contract_type'       => $contract,
            'contract_end_date'   => $contract === 'permanent' ? null : now()->addDays(rand(-10, 365)),
            'probation_months'    => $probationMonths,
            'probation_end_date'  => $joined->copy()->addMonths($probationMonths),
            'probation_status'    => $joined->copy()->addMonths($probationMonths)->isFuture() ? 'on_probation' : 'confirmed',
        ]);
        $e->company_id = $company->id;
        $e->save();

        $e->nextOfKin()->create([
            'name'         => $this->faker->name(),
            'relationship' => $this->faker->randomElement(['spouse', 'mother', 'father', 'sister', 'brother']),
            'phone'        => '07' . rand(10000000, 99999999),
        ]);

        if ($withUser && rand(1, 10) > 1) {
            $user = User::create([
                'company_id'          => $company->id,
                'name'                => $e->full_name,
                'email'               => $email,
                'password'            => self::PASSWORD,
                'activated_at'        => rand(1, 10) > 1 ? now()->subDays(rand(1, 300)) : null,
                'password_changed_at' => now()->subDays(rand(1, 200)),
            ]);
            $user->assignRole($p['model']->is_supervisor ? ['employee', 'supervisor'] : ['employee']);
            $e->forceFill(['user_id' => $user->id])->save();
        }

        return $e;
    }
}
