<?php

namespace Tests\Feature\Payroll;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\User;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Models\PayrollStructure;
use App\Modules\Payroll\Services\Calculation\CalculationInput;
use App\Modules\Payroll\Services\Calculation\PayrollCalculator;
use App\Modules\Payroll\Services\PayProfileProvisioner;
use App\Modules\Payroll\Services\StatutoryRateRepository;
use App\Modules\Payroll\Support\PayrollDefaults;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * QA round 5: final dues in the regular run, overlapping re-instated records, one proration rule, structures that
 * set pay, effective dates vs joining date, reimbursements reaching the run, audit noise and duplicate components.
 */
class PayrollQaRound5Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $officer;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Wiring Ltd', 'code' => 'WIR', 'is_active' => true]);
        $this->officer = $this->user('payroll-officer', 'Olive');
        $this->finance = $this->user('finance', 'Fiona');
    }

    private function user(string $role, string $name): User
    {
        $u = User::create(['company_id' => $this->company->id, 'name' => $name, 'email' => uniqid(strtolower($name)).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $u->assignRole($role);

        return $u->fresh();
    }

    private function employee(string $name, float $basic, string $joined = '2025-01-01', array $attrs = []): Employee
    {
        $e = new Employee(['first_name' => $name, 'last_name' => 'QA', 'phone' => '0712345678', 'employee_number' => uniqid('E'), 'kra_pin' => 'A001234567Z']);
        $e->company_id = $this->company->id;
        $e->status = $attrs['status'] ?? 'active';
        $e->gender = 'female';
        $e->date_of_joining = $joined;
        $e->previous_employee_id = $attrs['previous_employee_id'] ?? null;
        $e->employment_level_id = $attrs['employment_level_id'] ?? null;
        $e->save();
        if ($basic >= 0) {
            PayrollPayProfile::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id,
                'basic_salary' => $basic, 'payment_method' => 'cash', 'on_hold' => $e->status === 'exited']);
        }
        if ($basic > 0) {
            PayrollSalaryHistory::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id,
                'basic_salary' => $basic, 'effective_date' => $joined, 'reason' => 'initial']);
        }

        return $e->fresh();
    }

    private function exit(Employee $e, string $date): void
    {
        DB::table('employee_exits')->insert(['company_id' => $this->company->id, 'employee_id' => $e->id, 'exit_date' => $date,
            'reason' => 'resignation', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $e->status = 'exited';
        $e->save();
        PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $e->id)->update(['on_hold' => true]);
    }

    private function payrun(array $payload = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->officer)->postJson('/api/payroll/runs', $payload + ['period' => '2026-11', 'type' => 'regular', 'calculate' => true])
            ->assertCreated()->json('data');
    }

    private function line(int $runId, int $employeeId): ?PayrollRunEmployee
    {
        return PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $runId)->where('employee_id', $employeeId)->first();
    }

    // ---------------------------------------------------------------- #4 / #26 final dues in the regular run

    public function test_leaver_in_period_gets_leave_settlement_in_the_regular_run_unless_a_final_dues_run_exists(): void
    {
        LeaveType::seedDefaults($this->company);
        $leaver = $this->employee('Jane', 60000, '2026-09-01');
        $this->exit($leaver, '2026-11-20');
        $annual = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'ANNUAL')->first();
        $balances = app(LeaveBalanceService::class);
        $balances->post($balances->ensure($leaver, $annual, 2026, Carbon::parse('2026-10-22')), 'deduction', -3, 'Leave taken', reference: 'test:taken');

        $nov = $this->payrun();
        $items = $this->line($nov['id'], $leaver->id)->items()->get()->keyBy('code');
        // Accrued to 20 Nov (Sep + Oct + 20/30 of Nov = 4.67) − 3 taken = 1.67 days, paid at basic / 22.
        $this->assertEqualsWithDelta(round(60000 / 22 * 1.67, 2), $items['LEAVE_ENC']->amount, 0.01);
        $this->assertSame('leave_settlement', $items['LEAVE_ENC']->source);
        $this->assertEqualsWithDelta(60000 * 15 / 21, $items['BASIC']->amount, 0.05, '15 of November\'s 21 working days');

        // The leaver can be picked for notice pay / other one-offs in this run.
        $this->actingAs($this->officer)->getJson("/api/payroll/runs/{$nov['id']}/payable-employees")->assertOk()
            ->assertJsonFragment(['id' => $leaver->id, 'exit_date' => '2026-11-20']);
        $notice = PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'NOTICE_PAY')->first();
        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$nov['id']}/adjustments",
            ['employee_id' => $leaver->id, 'component_id' => $notice->id, 'amount' => 20000])->assertCreated();
        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$nov['id']}/calculate")->assertOk();
        $this->assertEquals(20000, $this->line($nov['id'], $leaver->id)->items()->where('code', 'NOTICE_PAY')->value('amount'));

        // A separate final-dues run takes the settlement over; the regular run must be recalculated without it.
        $final = $this->payrun(['type' => 'off_cycle', 'off_cycle_reason' => 'final_dues', 'employee_id' => $leaver->id]);
        $this->assertTrue((bool) PayrollRun::find($nov['id'])->needs_recalculation);
        $this->assertNotNull($this->line($final['id'], $leaver->id)->items()->where('code', 'LEAVE_ENC')->first());
        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$nov['id']}/calculate")->assertOk();
        $this->assertNull($this->line($nov['id'], $leaver->id)->items()->where('code', 'LEAVE_ENC')->first());
    }

    public function test_one_off_items_are_refused_for_employees_the_run_does_not_pay(): void
    {
        $gone = $this->employee('Gone', 50000);
        $this->exit($gone, '2026-09-30');
        $this->employee('Stay', 50000);
        $nov = $this->payrun();
        $bonus = PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'BONUS')->first();

        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$nov['id']}/adjustments",
            ['employee_id' => $gone->id, 'component_id' => $bonus->id, 'amount' => 1000])->assertStatus(422);
    }

    // ---------------------------------------------------------------- #21 overlapping records

    public function test_overlapping_successor_record_is_paid_only_after_the_predecessor_exit(): void
    {
        $old = $this->employee('Neema', 45000, '2026-10-15');
        $this->exit($old, '2026-11-26');
        $new = $this->employee('Neema', 45000, '2026-11-20', ['previous_employee_id' => $old->id]);

        $nov = $this->payrun();
        // 2–26 Nov = 19 working days on the old record; 27 and 30 Nov = 2 on the new one: 21 in all, never twice.
        $this->assertEquals(19, $this->line($nov['id'], $old->id)->days_worked);
        $this->assertEquals(2, $this->line($nov['id'], $new->id)->days_worked);
        $this->assertEqualsWithDelta(45000 * 2 / 21, $this->line($nov['id'], $new->id)->basic_pay, 0.05);
    }

    // ---------------------------------------------------------------- #25 one proration rule

    public function test_joiners_and_unpaid_leave_prorate_basic_and_allowances_alike(): void
    {
        $rates = app(StatutoryRateRepository::class)->forDate(Carbon::parse('2026-11-30'));
        $components = [
            ['code' => 'TRANSPORT', 'name' => 'Transport', 'category' => 'earning', 'calculation' => 'fixed', 'amount' => 4400, 'recurring' => true],
            ['code' => 'HOUSE', 'name' => 'House', 'category' => 'earning', 'calculation' => 'percent_of_basic', 'rate' => 15, 'recurring' => true],
        ];
        $base = ['basicSalary' => 44000, 'periodStart' => '2026-11-01', 'periodEnd' => '2026-11-30', 'workingDays' => 22, 'components' => $components];
        $calc = new PayrollCalculator;

        // Joined mid-month: employed 11 of 22 working days.
        $joiner = $calc->calculate(CalculationInput::make($base + ['employmentStart' => '2026-11-16', 'employedWorkingDays' => 11]), $rates);
        // Whole month, 11 days unpaid leave.
        $unpaid = $calc->calculate(CalculationInput::make($base + ['inputs' => ['unpaid_leave_days' => 11]]), $rates);
        // Whole month, 22 days at half pay.
        $half = $calc->calculate(CalculationInput::make($base + ['inputs' => ['half_pay_leave_days' => 22]]), $rates);

        foreach ([$joiner, $unpaid, $half] as $r) {
            $items = collect($r['items'])->keyBy('code');
            $this->assertEqualsWithDelta(22000, $r['basic_pay'], 0.01);
            $this->assertEqualsWithDelta(2200, $items['TRANSPORT']['amount'], 0.01, 'fixed allowance follows the basic pay factor');
            $this->assertEqualsWithDelta(3300, $items['HOUSE']['amount'], 0.01, 'percent of basic is taken on basic pay');
            $this->assertEqualsWithDelta(27500, $r['gross_pay'], 0.01);
        }
    }

    // ---------------------------------------------------------------- #6 / #45 / #56 structures and salary history

    public function test_applying_a_structure_sets_basic_salary_with_a_history_row(): void
    {
        $level = EmploymentLevel::create(['company_id' => $this->company->id, 'name' => 'Junior']);
        PayrollDefaults::ensureComponents($this->company->id);
        $house = PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'HOUSE')->first();
        $structure = PayrollStructure::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Junior scale',
            'employment_level_id' => $level->id, 'basic_salary' => 60000, 'components' => [['component_id' => $house->id, 'amount' => null, 'rate' => null]], 'active' => true]);
        $juma = $this->employee('Juma', 50000, '2026-09-01');

        $this->actingAs($this->officer)->postJson("/api/payroll/structures/{$structure->id}/apply",
            ['employee_ids' => [$juma->id], 'effective_from' => '2026-08-01'])->assertOk();

        $profile = PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $juma->id)->first();
        $this->assertEquals(60000, $profile->basic_salary);
        $this->assertSame($structure->id, $profile->structure_id);
        $row = PayrollSalaryHistory::withoutGlobalScopes()->where('employee_id', $juma->id)->latest('id')->first();
        $this->assertSame('structure', $row->reason);
        $this->assertSame('Structure applied', PayrollSalaryHistory::REASONS[$row->reason]);
        $this->assertSame('2026-09-01', $row->effective_date->toDateString(), 'never before the joining date');
        $this->assertEquals(50000, $row->previous_salary);
        $this->assertDatabaseHas('payroll_employee_components', ['employee_id' => $juma->id, 'component_id' => $house->id]);
    }

    public function test_hire_auto_applies_the_matching_structure_as_the_initial_salary(): void
    {
        $level = EmploymentLevel::create(['company_id' => $this->company->id, 'name' => 'Junior']);
        PayrollStructure::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Junior scale',
            'employment_level_id' => $level->id, 'basic_salary' => 55000, 'components' => [], 'active' => true]);
        $imani = $this->employee('Imani', -1, '2026-10-15', ['employment_level_id' => $level->id]);

        $profile = app(PayProfileProvisioner::class)->forHire($imani);

        $this->assertEquals(55000, $profile->basic_salary);
        $this->assertNotNull($profile->structure_id);
        $row = PayrollSalaryHistory::withoutGlobalScopes()->where('employee_id', $imani->id)->sole();
        $this->assertSame('initial', $row->reason);
        $this->assertSame('2026-10-15', $row->effective_date->toDateString());
    }

    public function test_first_salary_entered_after_hire_is_initial_and_dates_cannot_precede_joining(): void
    {
        $e = $this->employee('Wanjiru', 0, '2026-10-01');

        $this->actingAs($this->officer)->postJson("/api/payroll/employees/{$e->id}/salary",
            ['basic_salary' => 70000, 'effective_date' => '2026-09-01', 'reason' => 'increment'])->assertStatus(422)->assertJsonValidationErrors('effective_date');
        $this->actingAs($this->officer)->postJson("/api/payroll/employees/{$e->id}/salary",
            ['basic_salary' => 70000, 'effective_date' => '2026-10-01', 'reason' => 'increment'])->assertOk();
        $this->assertSame('initial', PayrollSalaryHistory::withoutGlobalScopes()->where('employee_id', $e->id)->sole()->reason);

        PayrollDefaults::ensureComponents($this->company->id);
        $house = PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'HOUSE')->first();
        $this->actingAs($this->officer)->postJson("/api/payroll/employees/{$e->id}/components",
            ['component_id' => $house->id, 'rate' => 15, 'effective_from' => '2026-09-15'])->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->actingAs($this->officer)->postJson("/api/payroll/employees/{$e->id}/components",
            ['component_id' => $house->id, 'rate' => 15, 'effective_from' => '2026-10-01'])->assertCreated();
    }

    // ---------------------------------------------------------------- #35 reimbursements, register, GL codes

    public function test_expense_queued_for_payroll_marks_the_run_stale_and_reaches_the_register(): void
    {
        $juma = $this->employee('Juma', 60000);
        $nov = $this->payrun();
        $claim = new ExpenseClaim(['employee_id' => $juma->id, 'description' => 'Taxi', 'category' => 'travel', 'amount' => 1500,
            'expense_date' => '2026-11-03', 'status' => 'approved']);
        $claim->company_id = $this->company->id;
        $claim->save();

        $this->actingAs($this->finance)->postJson("/api/expenses/{$claim->id}/pay", ['payment_method' => 'payroll'])->assertOk();
        $this->assertTrue((bool) PayrollRun::find($nov['id'])->needs_recalculation, 'the calculated run must be recalculated');

        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$nov['id']}/calculate")->assertOk();
        $line = $this->line($nov['id'], $juma->id);
        $this->assertEquals(1500, $line->reimbursements);

        $register = $this->actingAs($this->officer)->getJson("/api/payroll/runs/{$nov['id']}/export/register?format=json")->assertOk()->json();
        $this->assertContains('reimbursements', array_column($register['columns'], 'key'));
        $this->assertEquals(1500, collect($register['rows'])->firstWhere('employee_name', $juma->full_name)['reimbursements']);
    }

    // ---------------------------------------------------------------- #34 audit noise

    public function test_viewing_reports_and_calculating_do_not_add_noise_to_the_audit_log(): void
    {
        $this->employee('Alice', 80000);
        $nov = $this->payrun();
        $runLogs = fn () => Activity::where('subject_type', (new PayrollRun)->getMorphClass())->where('subject_id', $nov['id'])->pluck('description');

        $this->assertSame(['Payroll run created', 'Payroll calculated'], $runLogs()->all(), 'one entry per calculation, no generic "updated" row');

        $this->actingAs($this->officer)->getJson("/api/payroll/runs/{$nov['id']}/export/register?format=json")->assertOk();
        $this->assertNotContains('Payroll export downloaded', $runLogs()->all());
        $this->actingAs($this->officer)->get("/api/payroll/runs/{$nov['id']}/export/register")->assertOk();
        $this->assertContains('Payroll export downloaded', $runLogs()->all());
    }

    // ---------------------------------------------------------------- #57 duplicate components

    public function test_standard_components_are_not_duplicated_and_get_gl_codes(): void
    {
        $custom = PayrollComponent::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'PENSION_E', 'name' => 'Pension',
            'category' => 'deduction', 'calculation' => 'percent_of_basic', 'role' => 'pension_employee', 'active' => true]);
        PayrollComponent::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'TRANSPORT', 'name' => 'Transport',
            'category' => 'earning', 'calculation' => 'fixed', 'active' => true]);

        PayrollDefaults::ensureComponents($this->company->id);
        PayrollDefaults::ensureComponents($this->company->id);

        $codes = PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->pluck('code');
        $this->assertNotContains('PEN_EE', $codes->all(), 'the company already has a pension deduction');
        $this->assertSame($codes->count(), $codes->unique()->count());
        $this->assertSame('6120', PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'TRANSPORT')->value('gl_account'));
        $this->assertTrue($custom->fresh()->active);
    }
}
