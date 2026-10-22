<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollCalendar;
use App\Modules\Payroll\Models\PayrollPaymentFile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollStructure;
use App\Modules\Claims\Models\ClaimLimit;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Frequency;
use App\Modules\Performance\Models\QuestionCategory;
use App\Modules\Performance\Models\Question;
use App\Modules\Performance\Models\Questionnaire;
use App\Modules\Performance\Models\Recognition;
use App\Modules\Attendance\Models\AttendanceDevice;
use Illuminate\Database\Seeder;

class SystemDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedPayrollCalendar($company);
            $this->seedPayrollStructures($company);
            $this->seedPayrollPaymentFiles($company);
            $this->seedPayrollAdjustments($company);
            $this->seedClaimLimits($company);
            $this->seedPerformanceQuestionnaires($company);
            $this->seedRecognitions($company);
            $this->seedAttendanceDevices($company);
        });
    }

    private function seedPayrollCalendar(Company $company): void
    {
        $year = now()->year;

        for ($month = 1; $month <= 12; $month++) {
            $period   = \Carbon\Carbon::create($year, $month, 1);
            $cutoff   = $period->copy()->day(20);
            $payDate  = $period->copy()->endOfMonth()->subDays(2);
            $isPast   = $period->copy()->endOfMonth()->isPast();

            PayrollCalendar::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'period' => $period->toDateString()],
                [
                    'cutoff_date' => $cutoff->toDateString(),
                    'pay_date'    => $payDate->toDateString(),
                    'status'      => $isPast ? 'paid' : ($period->isCurrentMonth() ? 'processing' : 'scheduled'),
                    'notes'       => null,
                ]
            );
        }
    }

    private function seedPayrollStructures(Company $company): void
    {
        $levels = \DB::table('employment_levels')
            ->where('company_id', $company->id)
            ->get();

        $structures = [
            ['Junior Staff Structure',   'JSS',  30000,  60000,  40000],
            ['Mid-Level Structure',      'MLS',  60000,  100000, 75000],
            ['Senior Staff Structure',   'SSS',  100000, 180000, 130000],
            ['Management Structure',     'MGT',  180000, 350000, 250000],
        ];

        foreach ($structures as $i => [$name, $code, $min, $max, $basic]) {
            $level = $levels->get($i);
            PayrollStructure::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'code'                => $code,
                    'employment_level_id' => $level?->id,
                    'min_salary'          => $min,
                    'max_salary'          => $max,
                    'basic_salary'        => $basic,
                    'description'         => "Pay structure for {$name}.",
                    'active'              => true,
                ]
            );
        }
    }

    private function seedPayrollPaymentFiles(Company $company): void
    {
        $adminUser = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();

        if (!$adminUser) return;

        $paidRuns = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'paid')
            ->take(4)
            ->get();

        foreach ($paidRuns as $run) {
            if (PayrollPaymentFile::withoutGlobalScopes()->where('payroll_run_id', $run->id)->exists()) {
                continue;
            }

            $period = \Carbon\Carbon::parse($run->period);

            PayrollPaymentFile::create([
                'company_id'    => $company->id,
                'payroll_run_id'=> $run->id,
                'type'          => 'bank_eft',
                'provider'      => collect(['KCB Bank', 'Equity Bank', 'Co-operative Bank'])->random(),
                'records'       => $run->employee_count ?? rand(20, 60),
                'total_amount'  => $run->total_net ?? rand(1000000, 5000000),
                'filename'      => 'payroll_' . $period->format('Y_m') . '_bank.csv',
                'status'        => 'generated',
                'generated_by'  => $adminUser->id,
                'generated_at'  => $period->copy()->endOfMonth()->subDays(1),
            ]);
        }
    }

    private function seedPayrollAdjustments(Company $company): void
    {
        $adminUser = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();

        if (!$adminUser) return;

        $draftRun = PayrollRun::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'draft')
            ->first();

        if (!$draftRun) return;

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->take(5)
            ->get();

        $bonusComponent = \DB::table('payroll_components')
            ->where('company_id', $company->id)
            ->where('code', 'BONUS')
            ->first();

        $overtimeComponent = \DB::table('payroll_components')
            ->where('company_id', $company->id)
            ->where('code', 'OVERTIME')
            ->first();

        if (!$bonusComponent && !$overtimeComponent) return;

        foreach ($employees as $employee) {
            if (PayrollAdjustment::withoutGlobalScopes()
                ->where('payroll_run_id', $draftRun->id)
                ->where('employee_id', $employee->id)
                ->exists()) {
                continue;
            }

            $component = rand(0, 1) ? $bonusComponent : $overtimeComponent;
            if (!$component) continue;

            PayrollAdjustment::create([
                'company_id'     => $company->id,
                'payroll_run_id' => $draftRun->id,
                'employee_id'    => $employee->id,
                'component_id'   => $component->id,
                'amount'         => rand(3000, 25000),
                'description'    => $component->code === 'BONUS'
                    ? 'Q3 performance bonus'
                    : 'Overtime — project delivery',
                'created_by'     => $adminUser->id,
            ]);
        }
    }

    private function seedClaimLimits(Company $company): void
    {
        $adminUser = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $levelLimits = [
            'Management'  => [200000, 20000],
            'Senior Staff'=> [120000, 12000],
            'Mid-Level'   => [80000,  8000],
            'Junior Staff'=> [50000,  5000],
        ];

        foreach ($employees as $employee) {
            if (ClaimLimit::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $levelName = $employee->employmentLevel?->name ?? 'Junior Staff';
            [$annual, $monthly] = $levelLimits[$levelName] ?? [50000, 5000];

            ClaimLimit::create([
                'company_id'    => $company->id,
                'employee_id'   => $employee->id,
                'annual_limit'  => $annual,
                'monthly_limit' => $monthly,
                'updated_by'    => $adminUser?->id,
            ]);
        }
    }

    private function seedPerformanceQuestionnaires(Company $company): void
    {
        // Question categories
        $categories = [
            ['Core Values',        1],
            ['Job Performance',    2],
            ['Leadership',         3],
            ['Teamwork',           4],
            ['Communication',      5],
        ];

        $catModels = [];
        foreach ($categories as [$name, $sort]) {
            $catModels[$name] = QuestionCategory::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['sort' => $sort]
            );
        }

        // Questions per category
        $questionDefs = [
            'Core Values'     => [
                'Demonstrates integrity and ethical behaviour in all interactions.',
                'Upholds company values and policies consistently.',
                'Acts as a positive role model for colleagues.',
            ],
            'Job Performance' => [
                'Consistently meets or exceeds set targets and KPIs.',
                'Delivers high-quality work within agreed timelines.',
                'Takes initiative to solve problems without being prompted.',
            ],
            'Leadership'      => [
                'Provides clear direction and guidance to the team.',
                'Motivates and inspires team members to achieve goals.',
                'Makes sound decisions under pressure.',
            ],
            'Teamwork'        => [
                'Collaborates effectively with colleagues across departments.',
                'Shares knowledge and supports team members willingly.',
                'Handles conflict constructively and professionally.',
            ],
            'Communication'   => [
                'Communicates clearly and concisely in written and verbal form.',
                'Listens actively and responds appropriately.',
                'Keeps stakeholders informed of progress and issues.',
            ],
        ];

        foreach ($questionDefs as $catName => $questions) {
            $cat = $catModels[$catName] ?? null;
            if (!$cat) continue;
            foreach ($questions as $text) {
                Question::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'category_id' => $cat->id, 'question' => $text],
                    ['is_active' => true]
                );
            }
        }

        // Questionnaires for active cycle
        $cycle = Cycle::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->first();

        if (!$cycle) return;

        $frequency = Frequency::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', 'Annual')
            ->first();

        $hrUser = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'super-admin']))
            ->first();

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->with('primarySupervisor')
            ->get();

        $statuses = ['pending', 'pending', 'self_done', 'completed', 'completed'];

        foreach ($employees->take(15) as $employee) {
            if (Questionnaire::withoutGlobalScopes()
                ->where('cycle_id', $cycle->id)
                ->where('employee_id', $employee->id)
                ->exists()) {
                continue;
            }

            $status = $statuses[array_rand($statuses)];

            Questionnaire::create([
                'company_id'             => $company->id,
                'cycle_id'               => $cycle->id,
                'employee_id'            => $employee->id,
                'supervisor_id'          => $employee->primary_supervisor_id,
                'title'                  => "Annual Review {$cycle->name} — {$employee->full_name}",
                'due_date'               => \Carbon\Carbon::parse($cycle->end_date)->toDateString(),
                'frequency_id'           => $frequency?->id,
                'status'                 => $status,
                'self_submitted_at'      => in_array($status, ['self_done', 'completed'])
                    ? now()->subDays(rand(5, 30)) : null,
                'supervisor_submitted_at'=> $status === 'completed'
                    ? now()->subDays(rand(1, 10)) : null,
                'assigned_by_user_id'    => $hrUser?->id,
            ]);
        }
    }

    private function seedRecognitions(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $supervisors = $employees->filter(fn ($e) => $e->position?->is_supervisor);
        if ($supervisors->isEmpty()) return;

        $categories = ['excellence', 'innovation', 'teamwork', 'leadership', 'customer_focus'];
        $messages   = [
            'Exceptional performance this quarter — consistently exceeded all targets.',
            'Outstanding contribution to the system migration project.',
            'Went above and beyond to support the team during the audit period.',
            'Demonstrated excellent leadership during the branch opening.',
            'Received outstanding customer feedback for 3 consecutive months.',
            'Innovative solution that saved the company significant time and cost.',
            'Exemplary teamwork during the year-end close process.',
            'Consistently positive attitude and willingness to help colleagues.',
        ];

        $hrUser = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'supervisor']))
            ->first();

        $staff = $employees->reject(fn ($e) => $e->position?->is_supervisor)->values();
        if ($staff->isEmpty()) return;

        $count = min($staff->count(), rand(8, 15));
        foreach ($staff->random($count) as $employee) {
            if (Recognition::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('employee_id', $employee->id)
                ->exists()) {
                continue;
            }

            Recognition::create([
                'company_id'        => $company->id,
                'employee_id'       => $employee->id,
                'awarded_by_user_id'=> $hrUser?->id,
                'category'          => $categories[array_rand($categories)],
                'message'           => $messages[array_rand($messages)],
                'created_at'        => now()->subDays(rand(5, 180)),
            ]);
        }
    }

    private function seedAttendanceDevices(Company $company): void
    {
        $branches = \DB::table('branches')->where('company_id', $company->id)->get();
        $adminUser = User::withoutGlobalScopes()->where('company_id', $company->id)->value('id');

        foreach ($branches as $branch) {
            if (AttendanceDevice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->exists()) {
                continue;
            }

            $token = hash('sha256', $company->id . $branch->id . uniqid());

            AttendanceDevice::create([
                'company_id'  => $company->id,
                'branch_id'   => $branch->id,
                'name'        => $branch->name . ' Biometric Terminal',
                'serial'      => 'ZKT-' . strtoupper(substr(md5($branch->id), 0, 8)),
                'token_hash'  => $token,
                'token_hint'  => substr($token, 0, 8),
                'last_seen_at'=> now()->subHours(rand(1, 48)),
                'last_ip'     => '192.168.' . rand(1, 5) . '.' . rand(10, 200),
                'active'      => true,
                'created_by'  => $adminUser,
            ]);
        }
    }
}
