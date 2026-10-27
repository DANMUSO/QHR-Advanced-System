<?php

namespace Tests\Feature\ClaimsBenefits;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Claims\Models\MedicalClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** QA round 5: claim payment search, expense receipts, paid amount on the portal, approvals page guard. */
class QaRound5ClaimsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        Storage::fake('local');
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

    private function claim(Employee $employee, string $status, float $amount = 2000, ?float $approved = null, string $ref = ''): MedicalClaim
    {
        $c = new MedicalClaim([
            'claimant_type' => 'self', 'claimant_name' => $employee->full_name, 'claim_date' => now()->toDateString(),
            'provider_name' => 'Clinic', 'total_amount' => $amount,
        ]);
        $c->company_id = $this->company->id;
        $c->employee_id = $employee->id;
        $c->reference = $ref ?: uniqid('CLM-');
        $c->status = $status;
        $c->submitted_at = now();
        if ($status !== MedicalClaim::SUBMITTED) {
            $c->approved_amount = $approved ?? $amount;
            $c->hr_decided_at = now();
        }
        $c->save();

        return $c;
    }

    private function expense(array $overrides = []): array
    {
        return $overrides + ['description' => 'Taxi to client', 'category' => 'transport', 'amount' => 1500, 'expense_date' => today()->toDateString()];
    }

    private function postExpense(User $user, array $data)
    {
        return $this->actingAs($user)->post('/api/expenses', $data, ['Accept' => 'application/json']);
    }

    public function test_payment_search_filters_the_awaiting_tab(): void
    {
        $finance = $this->person('finance');
        $a = $this->claim($this->person('employee', null, 'Alpha')->employee, MedicalClaim::HR_APPROVED, ref: 'CLM-AAA-1');
        $this->claim($this->person('employee', null, 'Beta')->employee, MedicalClaim::HR_APPROVED, ref: 'CLM-BBB-2');

        $this->actingAs($finance)->getJson('/api/claims/payments?status=hr_approved')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($finance)->getJson('/api/claims/payments?status=hr_approved&search=AAA')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id)->assertJsonPath('totals.count', 1);
        $this->actingAs($finance)->getJson('/api/claims/payments?status=hr_approved&search=Beta')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($finance)->getJson('/api/claims/payments?status=hr_approved&search=nothing-matches')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_expense_needs_a_receipt_by_default_and_only_above_a_configured_threshold(): void
    {
        $employee = $this->person('employee');

        $this->postExpense($employee, $this->expense())->assertStatus(422)->assertJsonValidationErrors('receipt');
        $this->postExpense($employee, $this->expense(['receipt' => UploadedFile::fake()->create('r.exe', 10, 'application/x-msdownload')]))
            ->assertStatus(422)->assertJsonValidationErrors('receipt');
        $this->postExpense($employee, $this->expense(['receipt' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]))
            ->assertStatus(422)->assertJsonValidationErrors('receipt');

        $id = $this->postExpense($employee, $this->expense(['receipt' => UploadedFile::fake()->create('taxi.pdf', 100, 'application/pdf')]))
            ->assertCreated()->assertJsonPath('data.has_receipt', true)->json('data.id');
        $claim = ExpenseClaim::withoutGlobalScopes()->findOrFail($id);
        Storage::disk('local')->assertExists($claim->receipt_path);

        // Authorised download: the claimant and expense approvers yes, an unrelated colleague no.
        $this->actingAs($employee)->get("/api/expenses/{$id}/receipt")->assertOk();
        $hr = $this->person('hr-admin');
        $this->actingAs($hr)->get("/api/expenses/{$id}/receipt")->assertOk();
        $this->actingAs($this->person('employee', null, 'Nosy'))->get("/api/expenses/{$id}/receipt")->assertForbidden();

        // HR raises the threshold: small expenses no longer need a receipt, larger ones still do.
        $this->actingAs($hr)->putJson('/api/claims/settings/expenses', ['expense_receipt_threshold' => 2000])->assertOk();
        $this->actingAs($employee)->getJson('/api/expenses')->assertJsonPath('receipt_threshold', 2000);
        $this->postExpense($employee, $this->expense())->assertCreated();
        $this->postExpense($employee, $this->expense(['amount' => 2500]))->assertStatus(422)->assertJsonValidationErrors('receipt');
    }

    public function test_portal_shows_claimed_and_paid_amounts(): void
    {
        $user = $this->person('employee');
        $this->claim($user->employee, MedicalClaim::PAID, 3500, 3000);
        $this->claim($user->employee, MedicalClaim::PAID, 1200);

        $rows = collect($this->actingAs($user)->getJson('/api/claims')->assertOk()->json('data'))->sortByDesc('total_amount')->values();
        $this->assertSame('3,500 claimed · 3,000 paid', $rows[0]['amount_summary']);
        $this->assertEquals(3000, $rows[0]['paid_amount']);
        $this->assertSame('1,200 paid', $rows[1]['amount_summary']);
    }

    public function test_claim_approvals_page_is_only_for_approvers(): void
    {
        $plain = $this->person('employee', null, 'Plain');
        $this->actingAs($plain)->getJson('/api/claims/approvals/access')->assertOk()->assertJsonPath('allowed', false);
        $this->actingAs($plain)->getJson('/api/claims/approvals')->assertForbidden();

        $this->actingAs($this->person('hr-admin'))->getJson('/api/claims/approvals')->assertOk();

        // Escalated approver without claims.approve: allowed once a claim is routed to them.
        $escalated = $this->person('employee', null, 'Esc');
        $claim = $this->claim($this->person('employee', null, 'Claimant')->employee, MedicalClaim::SUBMITTED);
        $claim->forceFill(['routed_to_employee_id' => $escalated->employee->id])->save();
        $this->assertFalse($escalated->can('claims.approve'));
        $this->actingAs($escalated)->getJson('/api/claims/approvals/access')->assertJsonPath('allowed', true);
        $this->actingAs($escalated)->getJson('/api/claims/approvals')->assertOk()->assertJsonPath('data.0.id', $claim->id);
    }
}
