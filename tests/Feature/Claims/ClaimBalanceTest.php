<?php

namespace Tests\Feature\Claims;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Claims\Dashboard\ClaimBalanceWidget;
use App\Modules\Claims\Models\MedicalClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** QA round 2: the balance shown to the employee is limit − approved − pending, as ClaimLimits enforces. */
class ClaimBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        Storage::fake();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        $this->company->forceFill(['settings' => array_merge($this->company->settings ?? [], ['medical_annual_limit' => 50000, 'medical_monthly_limit' => 10000])])->save();
    }

    private function employee(): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => 'Peter Employee', 'email' => uniqid('e').'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole('employee');
        $employee = new Employee(['first_name' => 'Peter', 'last_name' => 'Employee', 'phone' => '0712345678', 'employee_number' => uniqid()]);
        $employee->company_id = $this->company->id;
        $employee->user_id = $user->id;
        $employee->status = 'active';
        $employee->save();

        return $user->fresh();
    }

    private function claim(Employee $employee, string $status, float $amount): void
    {
        $c = new MedicalClaim(['claimant_type' => 'self', 'claimant_name' => $employee->full_name, 'claim_date' => now()->toDateString(), 'provider_name' => 'Clinic', 'total_amount' => $amount]);
        $c->company_id = $this->company->id;
        $c->employee_id = $employee->id;
        $c->reference = uniqid('CLM-');
        $c->status = $status;
        $c->submitted_at = now();
        if ($status === MedicalClaim::HR_APPROVED) {
            $c->approved_amount = $amount;
        }
        $c->save();
    }

    public function test_pending_claims_reduce_the_available_balance_on_the_form_and_widget(): void
    {
        $user = $this->employee();
        $this->claim($user->employee, MedicalClaim::HR_APPROVED, 3000);
        $this->claim($user->employee, MedicalClaim::SUBMITTED, 5000);

        $this->actingAs($user)->getJson('/api/claims/summary?date='.now()->toDateString())->assertOk()
            ->assertJsonPath('balance.monthly_available', 2000)
            ->assertJsonPath('balance.monthly_pending', 5000)
            ->assertJsonPath('balance.annual_available', 42000)
            ->assertJsonPath('year_balance.monthly_available', 2000);

        $data = app(ClaimBalanceWidget::class)->data($user);
        $this->assertEquals(2000, $data['monthly_available']);
        $this->assertEquals(42000, $data['annual_available']);
        $this->assertEquals(5000, $data['monthly_pending']);

        // The server refuses what the display says is unavailable.
        $this->actingAs($user)->post('/api/claims', [
            'claimant_type' => 'self', 'claim_date' => now()->toDateString(), 'provider_name' => 'Clinic',
            'items' => [['category' => array_key_first(MedicalClaim::CATEGORIES), 'description' => 'Consultation', 'amount' => 2500]],
            'receipts' => [UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertSee('remaining monthly limit of KES 2,000.00', false);
    }
}
