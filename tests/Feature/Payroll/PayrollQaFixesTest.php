<?php

namespace Tests\Feature\Payroll;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Models\PayrollStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** QA round 2: no sign-off of one's own pay, final dues for scheduled exits, payment readiness, structure components. */
class PayrollQaFixesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $officer;

    private User $officer2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Journey QA', 'code' => 'JQA', 'is_active' => true]);
        $this->officer = $this->user(['payroll-officer'], 'Olive');
        $this->officer2 = $this->user(['payroll-officer'], 'Oscar');
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

    private function employee(string $name, float $basic, ?User $user = null, array $profile = []): Employee
    {
        $e = new Employee(['first_name' => $name, 'last_name' => 'Test', 'phone' => '0712345678', 'employee_number' => uniqid('E'), 'kra_pin' => 'A00'.rand(1000000, 9999999).'Z']);
        $e->company_id = $this->company->id;
        $e->status = 'active';
        $e->gender = 'female';
        $e->date_of_joining = '2025-01-01';
        $e->user_id = $user?->id;
        $e->save();
        PayrollPayProfile::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'basic_salary' => $basic, 'payment_method' => 'cash',
        ], $profile));
        PayrollSalaryHistory::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'basic_salary' => $basic,
            'effective_date' => '2025-01-01', 'reason' => 'initial',
        ]);

        return $e;
    }

    private function createRun(array $payload, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->officer)->postJson('/api/payroll/runs', $payload + ['period' => '2026-08', 'type' => 'regular', 'calculate' => true])
            ->assertCreated()->json('data');
    }

    public function test_user_cannot_review_approve_or_mark_paid_a_run_that_pays_them(): void
    {
        $peter = $this->user(['finance'], 'Peter');
        $other = $this->user(['finance'], 'Fiona');
        $this->employee('Peter', 90000, $peter);
        $this->employee('Alice', 80000);
        // A payroll officer who is also paid in the run.
        $this->employee('Oscar', 70000, $this->officer2);

        $run = $this->createRun([]);
        $this->assertSame(3, $run['employee_count']);

        // Review: officer2 is paid in the run, so cannot review it.
        $asOscar = $this->actingAs($this->officer2)->getJson("/api/payroll/runs/{$run['id']}")->json('data.can');
        $this->assertFalse($asOscar['review']);
        $this->assertTrue($asOscar['own_pay_blocked']);
        $this->actingAs($this->officer2)->postJson("/api/payroll/runs/{$run['id']}/review")
            ->assertForbidden()->assertJsonFragment(['message' => \App\Modules\Payroll\Services\PayrollRunService::OWN_PAY_MESSAGE]);

        // Grace (HR, payroll.review) reviews instead.
        $grace = $this->user(['hr-admin'], 'Grace');
        $this->actingAs($grace)->postJson("/api/payroll/runs/{$run['id']}/review")->assertOk();

        // Approve: Peter is paid in the run.
        $can = $this->actingAs($peter)->getJson("/api/payroll/runs/{$run['id']}")->json('data.can');
        $this->assertFalse($can['approve']);
        $this->assertTrue($can['own_pay_blocked']);
        $this->actingAs($peter)->postJson("/api/payroll/runs/{$run['id']}/approve")
            ->assertForbidden()->assertJsonFragment(['message' => \App\Modules\Payroll\Services\PayrollRunService::OWN_PAY_MESSAGE]);
        $this->assertSame('reviewed', PayrollRun::find($run['id'])->status);

        // The dashboard approval queue does not offer Peter his own run.
        $this->assertSame(0, app(\App\Modules\Payroll\Dashboard\Approvals\PayrollRunApprovalProvider::class)->countFor($peter));

        $this->actingAs($other)->postJson("/api/payroll/runs/{$run['id']}/approve")->assertOk();

        // Mark paid: same rule.
        $this->assertFalse($this->actingAs($peter)->getJson("/api/payroll/runs/{$run['id']}")->json('data.can.mark_paid'));
        $this->actingAs($peter)->postJson("/api/payroll/runs/{$run['id']}/mark-paid")->assertForbidden();
        $this->actingAs($other)->postJson("/api/payroll/runs/{$run['id']}/mark-paid")->assertOk();
        $this->assertSame('paid', PayrollRun::find($run['id'])->status);
    }

    public function test_scheduled_exit_is_eligible_for_final_dues_that_settle_advance_and_encash_leave(): void
    {
        $jane = $this->employee('Jane', 60000);
        DB::table('employee_exits')->insert([
            'company_id' => $this->company->id, 'employee_id' => $jane->id, 'exit_date' => '2026-11-20', 'reason' => 'resignation',
            'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PayrollSalaryAdvance::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $jane->id, 'amount' => 15000, 'repayment_months' => 3,
            'amount_recovered' => 5000, 'status' => 'approved', 'recovery_period' => '2026-10-01',
        ]);

        $this->actingAs($this->officer)->getJson('/api/payroll/leavers')->assertOk()
            ->assertJsonFragment(['id' => $jane->id, 'exit_date' => '2026-11-20', 'scheduled' => true]);

        // No exit_date sent: the scheduled exit date is used.
        $run = $this->createRun(['period' => '2026-11', 'type' => 'off_cycle', 'off_cycle_reason' => 'final_dues', 'employee_id' => $jane->id, 'leave_days' => 4]);
        $this->assertSame('2026-11-20', $run['options']['exit_date']);
        $line = PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run['id'])->where('employee_id', $jane->id)->firstOrFail();
        $items = $line->items()->get()->keyBy('code');
        $this->assertEquals(10000, $items['ADVANCE']->amount, 'final dues recover the outstanding advance in full');
        $this->assertEqualsWithDelta(60000 / 22 * 4, $items['LEAVE_ENC']->amount, 0.01);
        // Working-day proration: 15 of November 2026's 21 working days up to the 20th.
        $this->assertEqualsWithDelta(60000 * 15 / 21, $items['BASIC']->amount, 0.05);
    }

    public function test_payment_ready_requires_basic_salary_and_payment_details(): void
    {
        $zero = $this->employee('Zero', 0, null, ['payment_method' => 'mpesa', 'mpesa_phone' => '0711000002']);
        $noPhone = $this->employee('NoPhone', 50000, null, ['payment_method' => 'mpesa']);
        $ok = $this->employee('Ready', 50000, null, ['payment_method' => 'mpesa', 'mpesa_phone' => '0711000003']);

        $profile = fn (Employee $e) => $this->actingAs($this->officer)->getJson("/api/payroll/employees/{$e->id}/profile")->assertOk()->json('profile');

        $this->assertFalse($profile($zero)['payment_ready']);
        $this->assertContains('Basic salary is not set.', $profile($zero)['readiness_issues']);
        $this->assertFalse($profile($noPhone)['payment_ready']);
        $this->assertTrue($profile($ok)['payment_ready']);
        $this->assertSame('M-Pesa', $profile($ok)['payment_method_label']);
    }

    public function test_pension_cap_of_30000_applies_to_a_brand_new_company(): void
    {
        $fresh = Company::create(['name' => 'Brand New Ltd', 'code' => 'BNL', 'is_active' => true]);
        $user = User::create([
            'company_id' => $fresh->id, 'name' => 'Nina', 'email' => uniqid('nina').'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole('payroll-officer');

        $res = $this->actingAs($user->fresh())->getJson('/api/payroll/statutory')->assertOk();
        $cap = collect($res->json('data'))->firstWhere('type', 'pension_cap');
        $this->assertEquals(30000, $cap['amount']);
        $this->assertEquals(30000, $cap['cap']);
        $this->assertSame('Pension cap', $cap['type_label']);
        $this->assertEquals(30000, $res->json('effective.pensionCap'));

        // Even with no rows at all, the cap falls back to the statutory default.
        DB::table('payroll_statutory_rates')->delete();
        $this->assertEquals(30000, app(\App\Modules\Payroll\Services\StatutoryRateRepository::class)->forDate('2026-11-30')->pensionCap);
    }

    public function test_structure_components_are_saved_and_kept_when_omitted(): void
    {
        $house = \App\Modules\Payroll\Models\PayrollComponent::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'code' => 'HOUSE', 'name' => 'House', 'category' => 'earning', 'calculation' => 'fixed', 'active' => true,
        ]);
        $id = $this->actingAs($this->officer)->postJson('/api/payroll/structures', [
            'name' => 'Grade A', 'basic_salary' => 50000, 'components' => [['component_id' => $house->id, 'amount' => 8000, 'rate' => '']],
        ])->assertCreated()->json('data.id');
        $this->assertEquals([['component_id' => $house->id, 'amount' => 8000, 'rate' => null]], PayrollStructure::find($id)->components);

        $this->actingAs($this->officer)->putJson("/api/payroll/structures/{$id}", ['name' => 'Grade A1', 'basic_salary' => 52000])->assertOk();
        $this->assertCount(1, PayrollStructure::find($id)->components);
    }
}
