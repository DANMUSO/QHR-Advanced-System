<?php

namespace Tests\Feature\Payroll;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Payroll\Events\PayrollRunApproved;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Services\Calculation\CalculationInput;
use App\Modules\Payroll\Services\Calculation\PayrollCalculator;
use App\Modules\Payroll\Services\LoanService;
use App\Modules\Payroll\Services\StatutoryRateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Payroll journeys: run workflow + segregation of duties, leavers (exit month & final dues), advance instalments, permissions. */
class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $officer;

    private User $officer2;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        $this->officer = $this->user(['payroll-officer'], 'Olive');
        $this->officer2 = $this->user(['payroll-officer'], 'Oscar');
        $this->finance = $this->user(['finance'], 'Fiona');
    }

    private function user(array $roles, string $name): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => $name, 'email' => uniqid(strtolower($name)).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($roles);

        return $user->fresh();
    }

    private function employee(string $name, float $basic, array $attrs = []): Employee
    {
        $e = new Employee(['first_name' => $name, 'last_name' => 'Test', 'phone' => '0712345678', 'employee_number' => uniqid('E'), 'kra_pin' => 'A00'.rand(1000000, 9999999).'Z']);
        $e->company_id = $this->company->id;
        $e->status = $attrs['status'] ?? 'active';
        $e->gender = 'male';
        $e->date_of_joining = $attrs['date_of_joining'] ?? '2025-01-01';
        $e->save();
        PayrollPayProfile::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'basic_salary' => $basic, 'payment_method' => 'cash',
            'on_hold' => $attrs['on_hold'] ?? false,
        ]);
        PayrollSalaryHistory::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'basic_salary' => $basic,
            'effective_date' => '2025-01-01', 'reason' => 'initial',
        ]);

        return $e;
    }

    private function exitEmployee(Employee $e, string $date): void
    {
        DB::table('employee_exits')->insert(['company_id' => $this->company->id, 'employee_id' => $e->id, 'exit_date' => $date, 'reason' => 'resignation', 'created_at' => now(), 'updated_at' => now()]);
        $e->status = 'exited';
        $e->save();
        PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $e->id)->update(['on_hold' => true]);
    }

    private function createRun(array $payload, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->officer)->postJson('/api/payroll/runs', $payload + ['period' => '2026-08', 'type' => 'regular', 'calculate' => true])
            ->assertCreated()->json('data');
    }

    private function line(int $runId, int $employeeId): ?PayrollRunEmployee
    {
        return PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $runId)->where('employee_id', $employeeId)->first();
    }

    public function test_run_workflow_enforces_segregation_of_duties_and_dispatches_approval_event(): void
    {
        Event::fake([PayrollRunApproved::class]);
        $this->employee('Alice', 80000);
        $run = $this->createRun([]);
        $this->assertSame('calculated', $run['status']);
        $this->assertSame(1, $run['employee_count']);
        $this->assertFalse($run['can']['review'], 'calculator must not see the review button');

        // The calculator cannot review their own run.
        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$run['id']}/review")->assertForbidden();

        $reviewed = $this->actingAs($this->officer2)->getJson("/api/payroll/runs/{$run['id']}")->json('data');
        $this->assertTrue($reviewed['can']['review']);
        $this->actingAs($this->officer2)->postJson("/api/payroll/runs/{$run['id']}/review")->assertOk();

        // Payroll officers cannot approve; finance can.
        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$run['id']}/approve")->assertForbidden();
        $this->actingAs($this->finance)->postJson("/api/payroll/runs/{$run['id']}/approve")->assertOk();
        Event::assertDispatched(PayrollRunApproved::class, fn ($e) => $e->run->id === $run['id']);

        // mark_paid matches the route permission (payroll.approve).
        $asOfficer = $this->actingAs($this->officer)->getJson("/api/payroll/runs/{$run['id']}")->json('data.can');
        $this->assertFalse($asOfficer['mark_paid']);
        $this->actingAs($this->officer)->postJson("/api/payroll/runs/{$run['id']}/mark-paid")->assertForbidden();
        $this->actingAs($this->finance)->postJson("/api/payroll/runs/{$run['id']}/mark-paid")->assertOk();
        $this->assertSame('paid', PayrollRun::withoutGlobalScopes()->find($run['id'])->status);
    }

    public function test_leaver_is_prorated_in_exit_month_and_excluded_afterwards(): void
    {
        $stay = $this->employee('Stay', 62000);
        $leaver = $this->employee('Leaver', 62000);
        $this->exitEmployee($leaver, '2026-08-15');

        $aug = $this->createRun([]);
        $this->assertSame(2, $aug['employee_count']);
        $line = $this->line($aug['id'], $leaver->id);
        $this->assertNotNull($line, 'leaver must be paid for the exit month');
        // Working-day proration: 1–15 Aug 2026 has 10 of the month's 21 working days.
        $this->assertEquals(10, $line->days_worked);
        $this->assertEqualsWithDelta(62000 * 10 / 21, $line->basic_pay, 0.05);

        $sep = $this->createRun(['period' => '2026-09']);
        $this->assertSame(1, $sep['employee_count']);
        $this->assertNull($this->line($sep['id'], $leaver->id));
        $this->assertNotNull($this->line($sep['id'], $stay->id));
    }

    public function test_final_dues_pay_encashment_and_notice_and_recover_advance(): void
    {
        $leaver = $this->employee('Leaver', 44000);
        $this->exitEmployee($leaver, '2026-08-20');
        PayrollSalaryAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $leaver->id, 'amount' => 6000, 'repayment_months' => 3,
            'amount_recovered' => 2000, 'status' => 'approved', 'recovery_period' => '2026-07-01',
        ]);

        $this->actingAs($this->officer)->getJson('/api/payroll/leavers')->assertOk()->assertJsonFragment(['id' => $leaver->id, 'exit_date' => '2026-08-20']);

        $run = $this->createRun(['type' => 'off_cycle', 'off_cycle_reason' => 'final_dues', 'employee_id' => $leaver->id, 'notice_pay' => 10000, 'leave_days' => 5]);
        $this->assertSame('calculated', $run['status']);
        $line = $this->line($run['id'], $leaver->id);
        $this->assertNotNull($line);
        $items = $line->items()->get()->keyBy('code');
        $this->assertEqualsWithDelta(44000 * 14 / 21, $items['BASIC']->amount, 0.05, 'no regular run yet: final dues pay the prorated month (14 of 21 working days)');
        $this->assertEqualsWithDelta(44000 / 22 * 5, $items['LEAVE_ENC']->amount, 0.01);
        $this->assertEquals(10000, $items['NOTICE_PAY']->amount);
        $this->assertEquals(4000, $items['ADVANCE']->amount, 'final dues recover the whole outstanding advance');

        // The regular run for the same month must not pay the leaver's salary twice.
        $aug = $this->createRun([]);
        $this->assertNull($this->line($aug['id'], $leaver->id));
    }

    public function test_salary_advance_is_recovered_in_instalments(): void
    {
        $emp = $this->employee('Borrower', 60000);
        $user = $this->user(['employee'], 'Borrower');
        $emp->user_id = $user->id;
        $emp->save();

        $loans = app(LoanService::class);
        $advance = $loans->requestAdvance($emp, 9000, 'School fees', 3, $user);
        $this->assertSame('pending', $advance->status);
        // Above advance_max_percent (50% of 60,000) is refused.
        $this->actingAs($user)->postJson('/api/payroll/advances/request', ['amount' => 40000, 'reason' => 'x', 'repayment_months' => 2])->assertStatus(422);

        $this->actingAs($this->finance)->postJson("/api/payroll/advances/{$advance->id}/approve", ['recovery_period' => '2026-08'])->assertOk();

        $aug = $this->createRun([]);
        $deduction = $this->line($aug['id'], $emp->id)->items()->where('code', 'ADVANCE')->first();
        $this->assertEquals(3000, $deduction->amount);
        $this->actingAs($this->officer2)->postJson("/api/payroll/runs/{$aug['id']}/review")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/payroll/runs/{$aug['id']}/approve")->assertOk();

        $advance->refresh();
        $this->assertSame('approved', $advance->status);
        $this->assertEquals(3000, $advance->amount_recovered);

        $sep = $this->createRun(['period' => '2026-09']);
        $this->assertEquals(3000, $this->line($sep['id'], $emp->id)->items()->where('code', 'ADVANCE')->value('amount'));
    }

    public function test_reports_permission_can_read_and_export_runs_but_not_manage(): void
    {
        $this->employee('Alice', 50000);
        $run = $this->createRun([]);
        $reporter = $this->user(['employee'], 'Rita');
        $reporter->givePermissionTo('payroll.reports');

        $this->actingAs($reporter)->getJson('/api/payroll/runs')->assertOk()->assertJsonPath('data.0.id', $run['id']);
        $this->actingAs($reporter)->getJson("/api/payroll/runs/{$run['id']}/employees")->assertOk();
        $this->actingAs($reporter)->getJson("/api/payroll/runs/{$run['id']}/export/register?format=json")->assertOk();
        $this->actingAs($reporter)->postJson("/api/payroll/runs/{$run['id']}/calculate")->assertForbidden();
    }

    public function test_view_salary_can_view_but_not_edit_pay_profile(): void
    {
        $emp = $this->employee('Alice', 50000);
        $viewer = $this->user(['employee'], 'Vera');
        $viewer->givePermissionTo(['employees.view-salary', 'employees.view']);

        $this->actingAs($viewer)->getJson("/api/payroll/employees/{$emp->id}/profile")->assertOk()->assertJsonPath('can_edit', false);
        $this->actingAs($viewer)->postJson("/api/payroll/employees/{$emp->id}/salary", ['basic_salary' => 1, 'effective_date' => '2026-08-01', 'reason' => 'increment'])->assertForbidden();
    }

    public function test_reimbursements_are_added_to_net_pay_outside_gross_and_tax(): void
    {
        $rates = app(StatutoryRateRepository::class)->forDate('2026-08-31');
        $calc = new PayrollCalculator;
        $base = ['basicSalary' => 50000, 'periodStart' => '2026-08-01', 'periodEnd' => '2026-08-31'];
        $without = $calc->calculate(CalculationInput::make($base), $rates);
        $with = $calc->calculate(CalculationInput::make($base + ['inputs' => ['reimbursements' => 2500]]), $rates);

        $this->assertEquals($without['gross_pay'], $with['gross_pay']);
        $this->assertEquals($without['paye'], $with['paye']);
        $this->assertEquals($without['shif'], $with['shif']);
        $this->assertEqualsWithDelta($without['net_pay'] + 2500, $with['net_pay'], 0.001);
        $this->assertEquals(2500, $with['reimbursements']);
        $this->assertContains('REIMB', array_column($with['items'], 'code'));
    }
}
