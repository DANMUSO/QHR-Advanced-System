<?php

namespace Tests\Feature\Payroll;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Services\LifecycleService;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Support\PayrollSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Role-based QA round (Juma / Zawadi / Wanjiru / Neema): payroll controls when every approver is also on the payroll,
 * no self-service pay changes by the preparer, no sign-off over error flags, and no overlapping re-instatement.
 */
class PayrollQaRound3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Wiring Ltd', 'code' => 'WIR', 'is_active' => true]);
    }

    /** A user with the given roles who is also an employee on the payroll. */
    private function staff(string $name, array $roles, float $basic = 50000): User
    {
        $user = User::create(['company_id' => $this->company->id, 'name' => $name, 'email' => strtolower($name).'@w.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $user->assignRole($roles);
        $e = new Employee(['first_name' => $name, 'last_name' => 'Test', 'phone' => '07'.rand(10000000, 99999999),
            'employee_number' => uniqid('W'), 'kra_pin' => 'A00'.rand(1000000, 9999999).'Z']);
        $e->company_id = $this->company->id;
        $e->user_id = $user->id;
        $e->status = 'active';
        $e->gender = 'female';
        $e->date_of_joining = '2025-01-01';
        $e->save();
        PayrollPayProfile::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'basic_salary' => $basic, 'payment_method' => 'cash']);
        PayrollSalaryHistory::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'basic_salary' => $basic, 'effective_date' => '2025-01-01', 'reason' => 'initial']);

        return $user->fresh();
    }

    private function calculatedRun(User $officer): int
    {
        return $this->actingAs($officer)->postJson('/api/payroll/runs', ['period' => '2026-08', 'type' => 'regular', 'calculate' => true])
            ->assertCreated()->json('data.id');
    }

    public function test_approvers_paid_in_the_run_are_blocked_unless_hr_allows_own_pay_signoff(): void
    {
        $zawadi = $this->staff('Zawadi', ['payroll-officer', 'employee']);
        $wanjiru = $this->staff('Wanjiru', ['hr-admin', 'employee']);
        $neema = $this->staff('Neema', ['finance', 'employee']);
        $run = $this->calculatedRun($zawadi);

        $this->actingAs($wanjiru)->postJson("/api/payroll/runs/{$run}/review")->assertForbidden();

        // The preparer cannot relax the control; HR can.
        $settings = $this->actingAs($zawadi)->getJson('/api/payroll/settings')->json('data');
        $this->actingAs($zawadi)->putJson('/api/payroll/settings', ['own_pay_signoff' => true] + $settings)->assertForbidden();
        $this->actingAs($zawadi)->putJson('/api/payroll/settings/own-pay-signoff', ['allowed' => true])->assertForbidden();
        $this->actingAs($wanjiru)->putJson('/api/payroll/settings/own-pay-signoff', ['allowed' => true])->assertOk();

        $this->actingAs($wanjiru)->postJson("/api/payroll/runs/{$run}/review")->assertOk();
        $this->actingAs($neema)->postJson("/api/payroll/runs/{$run}/approve")->assertOk();
        $this->assertTrue(DB::table('activity_log')->where('description', 'like', '%paid in this run%')->exists(), 'own-pay sign-off is logged');
    }

    public function test_nobody_can_change_their_own_pay(): void
    {
        $zawadi = $this->staff('Zawadi', ['payroll-officer', 'employee']);
        $this->staff('Juma', ['employee']);
        $run = $this->calculatedRun($zawadi);
        $me = $zawadi->employee->id;
        $bonus = PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'BONUS')->value('id');

        $this->actingAs($zawadi)->postJson("/api/payroll/runs/{$run}/adjustments", ['employee_id' => $me, 'component_id' => $bonus, 'amount' => 1])->assertForbidden();
        $this->actingAs($zawadi)->postJson("/api/payroll/employees/{$me}/salary", ['basic_salary' => 999999, 'effective_date' => '2026-08-01', 'reason' => 'increment'])->assertForbidden();
    }

    public function test_a_run_with_an_error_flag_cannot_be_reviewed(): void
    {
        $zawadi = $this->staff('Zawadi', ['payroll-officer', 'employee']);
        $hr = User::create(['company_id' => $this->company->id, 'name' => 'HR', 'email' => 'hr@w.test', 'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $hr->assignRole('hr-admin');
        $this->staff('Wanjiru', ['employee'], 0); // zero basic → error flag
        $run = $this->calculatedRun($zawadi);

        $this->actingAs($hr)->postJson("/api/payroll/runs/{$run}/review")->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_reinstatement_cannot_start_on_or_before_the_exit_date(): void
    {
        $neema = $this->staff('Neema', ['employee'])->employee;
        DB::table('employee_exits')->insert(['company_id' => $this->company->id, 'employee_id' => $neema->id, 'exit_date' => '2026-10-26',
            'reason' => 'resignation', 'created_at' => now(), 'updated_at' => now()]);
        $neema->forceFill(['status' => 'exited'])->save();

        $this->expectException(ValidationException::class);
        app(LifecycleService::class)->reinstate($neema->fresh(), ['date_of_joining' => '2026-10-20', 'contract_type' => 'permanent', 'employee_number' => 'W-2'], false, null);
    }
}
