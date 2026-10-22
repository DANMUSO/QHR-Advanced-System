<?php

namespace Tests\Feature\ClaimsBenefits;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Models\TravelRequest;
use App\Modules\Benefits\Notifications\ExpenseNotification;
use App\Modules\Benefits\Payroll\ExpenseReimbursementInputs;
use App\Modules\Claims\Models\ClaimApprover;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Notifications\ClaimNotification;
use App\Modules\Claims\Services\ClaimLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Claims & Benefits journeys: finance payment queue, claim approvers/limits, travel approvals, expense payments. */
class ClaimsBenefitsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
    }

    private function person(string $role, ?Employee $supervisor = null, string $name = 'Test'): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => "{$name} {$role}", 'email' => uniqid($role).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($role);
        $employee = new Employee(['first_name' => $name, 'last_name' => ucfirst($role), 'phone' => '0712345678', 'employee_number' => uniqid()]);
        $employee->company_id = $this->company->id;
        $employee->user_id = $user->id;
        $employee->status = 'active';
        $employee->primary_supervisor_id = $supervisor?->id;
        $employee->save();

        return $user->fresh();
    }

    private function claim(Employee $employee, string $status, float $amount = 2000): MedicalClaim
    {
        $c = new MedicalClaim([
            'claimant_type' => 'self', 'claimant_name' => $employee->full_name, 'claim_date' => now()->toDateString(),
            'provider_name' => 'Clinic', 'total_amount' => $amount,
        ]);
        $c->company_id = $this->company->id;
        $c->employee_id = $employee->id;
        $c->reference = uniqid('CLM-');
        $c->status = $status;
        $c->submitted_at = now();
        if ($status === MedicalClaim::HR_APPROVED) {
            $c->approved_amount = $amount;
            $c->hr_decided_at = now();
        }
        $c->save();

        return $c;
    }

    public function test_finance_lists_and_pays_hr_approved_claims(): void
    {
        $finance = $this->person('finance');
        $employee = $this->person('employee')->employee;
        $claim = $this->claim($employee, MedicalClaim::HR_APPROVED);

        $this->actingAs($finance)->getJson('/api/claims/payments')
            ->assertOk()->assertJsonPath('data.0.id', $claim->id)->assertJsonPath('totals.count', 1);

        // Payment details are required for a single payment.
        $this->actingAs($finance)->postJson("/api/claims/{$claim->id}/pay", [])->assertUnprocessable();
        $this->actingAs($finance)->postJson("/api/claims/{$claim->id}/pay", ['payment_method' => 'mpesa', 'payment_reference' => 'QWE123'])
            ->assertOk()->assertJsonPath('data.status', MedicalClaim::PAID);

        Notification::assertSentTo($employee->user, ClaimNotification::class, fn ($n) => $n->event === 'paid');
        $this->assertSame('QWE123', $claim->fresh()->payment_reference);
    }

    public function test_bulk_pay_requires_claims_pay(): void
    {
        $employee = $this->person('employee');
        $claim = $this->claim($this->person('employee', null, 'Other')->employee, MedicalClaim::HR_APPROVED);

        $this->actingAs($employee)->postJson('/api/claims/bulk-pay', ['ids' => [$claim->id]])->assertForbidden();
        $this->actingAs($this->person('finance'))->postJson('/api/claims/bulk-pay', ['ids' => [$claim->id]])
            ->assertOk()->assertJsonPath('paid.0', $claim->id);
    }

    public function test_finance_rejection_restores_the_balance(): void
    {
        $finance = $this->person('finance');
        $employee = $this->person('employee')->employee;
        $claim = $this->claim($employee, MedicalClaim::HR_APPROVED, 3000);
        $limits = app(ClaimLimits::class);
        $before = $limits->balanceFor($employee)['annual_balance'];

        $this->actingAs($finance)->postJson("/api/claims/{$claim->id}/finance-reject", [])->assertUnprocessable();
        $this->actingAs($finance)->postJson("/api/claims/{$claim->id}/finance-reject", ['reason' => 'Duplicate receipt'])
            ->assertOk()->assertJsonPath('data.status', MedicalClaim::FINANCE_REJECTED);

        $this->assertSame($before + 3000, $limits->balanceFor($employee->fresh())['annual_balance']);
        Notification::assertSentTo($employee->user, ClaimNotification::class,
            fn ($n, $channels) => $n->event === 'finance_rejected' && in_array('mail', $channels, true) && count($channels) === 3);
    }

    public function test_assigning_claim_approvers_grants_permissions(): void
    {
        $hr = $this->person('hr-admin');
        $medical = $this->person('employee', null, 'Medic');
        $payer = $this->person('employee', null, 'Payer');
        $this->assertFalse($medical->can('claims.approve'));

        $this->actingAs($this->person('employee'))->putJson('/api/claims/settings/approvers', [])->assertForbidden();
        $this->actingAs($hr)->putJson('/api/claims/settings/approvers', [
            'medical_approver_id' => $medical->employee->id, 'finance_approver_id' => $payer->employee->id,
        ])->assertOk();

        $this->assertTrue($medical->fresh()->can('claims.approve'));
        $this->assertTrue($payer->fresh()->can('claims.pay'));
        $this->assertSame($medical->employee->id, ClaimApprover::forCompany($this->company->id)->medical_approver_id);

        // Re-assigning revokes the direct grant from the previous holder.
        $other = $this->person('employee', null, 'Other');
        $this->actingAs($hr)->putJson('/api/claims/settings/approvers', ['medical_approver_id' => $other->employee->id])->assertOk();
        $this->assertFalse($medical->fresh()->can('claims.approve'));
        $this->assertFalse($payer->fresh()->can('claims.pay'));
        $this->assertTrue($other->fresh()->can('claims.approve'));
    }

    public function test_hr_edits_an_employee_limit_override(): void
    {
        $hr = $this->person('hr-admin');
        $employee = $this->person('employee')->employee;

        $this->actingAs($hr)->putJson("/api/claims/limits/{$employee->id}", ['annual_limit' => 50000, 'monthly_limit' => 5000])->assertOk();
        $this->assertSame(50000.0, app(ClaimLimits::class)->limitsFor($employee)['annual']);

        $this->actingAs($hr)->putJson("/api/claims/limits/{$employee->id}", ['annual_limit' => null, 'monthly_limit' => null])->assertOk();
        $this->assertFalse(app(ClaimLimits::class)->limitsFor($employee)['overridden']);
    }

    public function test_travel_request_goes_to_supervisor_who_approves_or_rejects(): void
    {
        $supervisor = $this->person('supervisor');
        $staff = $this->person('employee', $supervisor->employee);
        $trip = ['destination' => 'Mombasa', 'purpose' => 'Client visit', 'departure_date' => now()->addDays(3)->toDateString(), 'return_date' => now()->addDays(5)->toDateString(), 'estimated_cost' => 15000];

        $id = $this->actingAs($staff)->postJson('/api/expenses/travel', $trip)->assertCreated()->json('data.id');
        $this->assertSame($supervisor->employee->id, TravelRequest::find($id)->approver_employee_id);
        Notification::assertSentTo($supervisor, ExpenseNotification::class, fn ($n) => $n->event === 'submitted');

        $this->actingAs($supervisor)->getJson('/api/expenses/travel/approvals')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($staff)->postJson("/api/expenses/travel/{$id}/approve")->assertForbidden();
        $this->actingAs($supervisor)->postJson("/api/expenses/travel/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        Notification::assertSentTo($staff, ExpenseNotification::class, fn ($n) => $n->event === 'approved');

        $id2 = $this->actingAs($staff)->postJson('/api/expenses/travel', $trip)->json('data.id');
        $this->actingAs($supervisor)->postJson("/api/expenses/travel/{$id2}/reject", [])->assertUnprocessable();
        $this->actingAs($supervisor)->postJson("/api/expenses/travel/{$id2}/reject", ['reason' => 'Budget freeze'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_expense_paid_via_payroll_feeds_reimbursements_and_settles_on_run_approval(): void
    {
        $supervisor = $this->person('supervisor');
        $staff = $this->person('employee', $supervisor->employee);
        $finance = $this->person('finance');

        $id = $this->actingAs($staff)->postJson('/api/expenses', [
            'description' => 'Taxi to client', 'category' => 'transport', 'amount' => 1200, 'expense_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
        Notification::assertSentTo($supervisor, ExpenseNotification::class, fn ($n) => $n->event === 'submitted');

        $this->actingAs($supervisor)->postJson("/api/expenses/{$id}/reject", [])->assertUnprocessable(); // reason required
        $this->actingAs($supervisor)->postJson("/api/expenses/{$id}/approve")->assertOk();
        Notification::assertSentTo($finance, ExpenseNotification::class, fn ($n) => $n->event === 'awaiting_payment');

        $this->actingAs($supervisor)->getJson('/api/expenses/payments')->assertForbidden();
        $this->actingAs($finance)->getJson('/api/expenses/payments')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($finance)->postJson("/api/expenses/{$id}/pay", ['payment_method' => 'bank'])->assertUnprocessable(); // reference
        $this->actingAs($finance)->postJson("/api/expenses/{$id}/pay", ['payment_method' => 'payroll'])
            ->assertOk()->assertJsonPath('data.status', 'payroll_pending');

        $inputs = (new ExpenseReimbursementInputs)->inputsFor($staff->employee, now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(['reimbursements' => 1200.0], $inputs);

        // PayrollRunApproved: claims included in the run are marked paid.
        $line = new class { public $employee_id; public $inputs; public $reimbursements = null; };
        $line->employee_id = $staff->employee->id;
        $line->inputs = ['reimbursements' => 1200.0];
        $run = new class($line) {
            public $id = 99; public $period; public $calculated_at;
            public function __construct(private $line) { $this->period = now(); $this->calculated_at = now()->addMinute(); }
            public function lines() { $l = $this->line; return new class($l) { public function __construct(private $l) {} public function withoutGlobalScopes() { return $this; } public function get() { return collect([$this->l]); } }; }
        };
        ExpenseReimbursementInputs::settle((object) ['run' => $run]);

        $claim = ExpenseClaim::find($id);
        $this->assertSame('paid', $claim->status);
        $this->assertSame(99, (int) $claim->payroll_run_id);
        $this->assertSame([], (new ExpenseReimbursementInputs)->inputsFor($staff->employee, now()->startOfMonth(), now()->endOfMonth()));
    }
}
