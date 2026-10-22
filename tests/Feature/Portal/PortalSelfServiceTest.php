<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Models\EmployeeLetter;
use App\Modules\Employees\Models\LetterRequest;
use App\Modules\Employees\Models\ProfileChangeRequest;
use App\Modules\Employees\Models\ResignationRequest;
use App\Modules\Payroll\Models\PayrollPayProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** My Portal self-service: sensitive profile changes go through HR, resignations, letter requests and my contracts. */
class PortalSelfServiceTest extends TestCase
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

    private function person(string $role, string $name): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => $name, 'email' => uniqid($role).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($role === 'employee' ? ['employee'] : [$role, 'employee']);
        $e = new Employee(['first_name' => $name, 'last_name' => 'Test', 'phone' => '07'.random_int(10000000, 99999999),
            'employee_number' => uniqid('E'), 'national_id' => (string) random_int(10000000, 99999999), 'kra_pin' => 'A000000001Z']);
        $e->company_id = $this->company->id;
        $e->user_id = $user->id;
        $e->status = 'active';
        $e->gender = 'female';
        $e->save();

        return $user->fresh();
    }

    public function test_sensitive_profile_fields_create_a_change_request_instead_of_saving(): void
    {
        $user = $this->person('employee', 'Mary');
        $e = $user->employee;

        $this->actingAs($user)->putJson('/api/profile', [
            'phone' => '0799111222', 'residence' => 'Kilimani',
            'national_id' => '30111222', 'kra_pin' => 'A123456789Z', 'nssf_number' => 'NS-1',
            'next_of_kin' => [['name' => 'John Doe', 'relationship' => 'brother', 'phone' => '0711000000']],
        ])->assertOk()->assertJsonPath('data.pending_change.changes.kra_pin', 'A123456789Z');

        $e->refresh();
        $this->assertSame('0799111222', $e->phone);           // plain field saved directly
        $this->assertSame('Kilimani', $e->residence);
        $this->assertSame('A000000001Z', $e->kra_pin);        // sensitive fields untouched
        $this->assertNotSame('30111222', $e->national_id);
        $this->assertSame(0, $e->nextOfKin()->count());

        $request = ProfileChangeRequest::withoutGlobalScopes()->where('employee_id', $e->id)->sole();
        $this->assertSame('pending', $request->status);
        $this->assertEqualsCanonicalizing(['national_id', 'kra_pin', 'nssf_number', 'next_of_kin'], array_keys($request->changes));
    }

    public function test_hr_approval_applies_the_change_including_bank_details_to_the_pay_profile(): void
    {
        $user = $this->person('employee', 'Mary');
        $hr = $this->person('hr-admin', 'Grace');
        $e = $user->employee;

        $this->actingAs($user)->postJson('/api/profile/change-requests', [
            'kra_pin' => 'A123456789Z',
            'payment' => ['payment_method' => 'bank', 'bank_name' => 'KCB', 'bank_branch' => 'Moi Ave', 'account_name' => 'Mary Test', 'account_number' => '1234567890'],
        ])->assertCreated();
        $id = ProfileChangeRequest::withoutGlobalScopes()->value('id');

        // The requester cannot use the HR queue.
        $this->actingAs($user)->postJson("/api/staff-requests/profile-changes/{$id}/approve")->assertForbidden();

        $this->actingAs($hr)->postJson("/api/staff-requests/profile-changes/{$id}/approve")->assertOk();

        $this->assertSame('A123456789Z', $e->fresh()->kra_pin);
        $profile = PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $e->id)->sole();
        $this->assertSame('bank', $profile->payment_method);
        $this->assertSame('KCB', $profile->bank_name);
        $this->assertSame('1234567890', $profile->account_number);
        $this->assertSame('approved', ProfileChangeRequest::withoutGlobalScopes()->find($id)->status);
    }

    public function test_a_resignation_request_is_created_and_reaches_hr(): void
    {
        $user = $this->person('employee', 'Mary');
        $hr = $this->person('hr-admin', 'Grace');

        $this->actingAs($user)->postJson('/api/profile/resignations', [
            'last_working_day' => now()->addMonth()->toDateString(), 'reason' => 'Relocating abroad',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        // Only one pending resignation at a time.
        $this->actingAs($user)->postJson('/api/profile/resignations', [
            'last_working_day' => now()->addMonth()->toDateString(), 'reason' => 'Again please',
        ])->assertStatus(422);

        $this->assertSame(1, ResignationRequest::withoutGlobalScopes()->where('employee_id', $user->employee->id)->count());
        $this->actingAs($hr)->getJson('/api/staff-requests/resignations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_letter_request_is_created_and_hr_issues_it(): void
    {
        $user = $this->person('employee', 'Mary');
        $hr = $this->person('hr-admin', 'Grace');

        $this->actingAs($user)->postJson('/api/profile/letter-requests', ['template' => 'warning', 'purpose' => 'Not allowed'])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/profile/letter-requests', [
            'template' => 'employment_confirmation', 'addressed_to' => 'KCB Bank', 'purpose' => 'Loan application',
        ])->assertCreated();
        $request = LetterRequest::withoutGlobalScopes()->sole();
        $this->assertSame('pending', $request->status);

        $this->actingAs($hr)->postJson("/api/staff-requests/letters/{$request->id}/approve", [])->assertOk();
        $request->refresh();
        $this->assertSame('issued', $request->status);
        $this->assertTrue(EmployeeLetter::withoutGlobalScopes()->whereKey($request->letter_id)->where('employee_id', $user->employee->id)->exists());

        $this->actingAs($user)->getJson('/api/profile/requests')->assertOk()->assertJsonCount(1, 'letters');
    }

    public function test_my_contracts_are_scoped_to_the_signed_in_employee(): void
    {
        $mary = $this->person('employee', 'Mary');
        $john = $this->person('employee', 'John');
        foreach ([[$mary, 'C-1'], [$mary, 'C-2'], [$john, 'C-3']] as [$u, $ref]) {
            $c = new EmployeeContract(['employee_id' => $u->employee->id, 'reference' => $ref, 'contract_type' => 'permanent', 'start_date' => '2025-01-01', 'status' => 'active']);
            $c->company_id = $this->company->id;
            $c->save();
        }

        $res = $this->actingAs($mary)->getJson('/api/profile/contracts')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['C-1', 'C-2'], collect($res->json('data'))->pluck('reference')->all());
    }
}
