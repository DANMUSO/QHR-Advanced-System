<?php

namespace Tests\Feature\LeaveAttendance;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

abstract class LeaveAttendanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = $this->makeCompany('Acme Ltd', 'ACM');
    }

    protected function makeCompany(string $name, string $code): Company
    {
        $company = Company::create(['name' => $name, 'code' => $code, 'is_active' => true]);
        LeaveType::seedDefaults($company);

        return $company;
    }

    /** A user + employee record. $roles e.g. ['employee'] or ['supervisor', 'employee']. */
    protected function person(array $roles, ?Employee $supervisor = null, string $name = 'Test', ?Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::create([
            'company_id' => $company->id, 'name' => "{$name} ".implode('-', $roles), 'email' => uniqid($roles[0]).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($roles);
        $employee = new Employee(['first_name' => $name, 'last_name' => ucfirst($roles[0]), 'phone' => '0712345678', 'employee_number' => uniqid('E')]);
        $employee->company_id = $company->id;
        $employee->user_id = $user->id;
        $employee->status = 'active';
        $employee->gender = 'female';
        $employee->primary_supervisor_id = $supervisor?->id;
        $employee->save();

        return $user->fresh();
    }

    protected function annual(?Company $company = null): LeaveType
    {
        return LeaveType::withoutGlobalScopes()->where('company_id', ($company ?? $this->company)->id)->where('code', 'ANNUAL')->firstOrFail();
    }

    /** A Monday at least a week ahead, inside the current year when possible. */
    protected function nextMonday(): \Carbon\Carbon
    {
        return today()->next(\Carbon\Carbon::MONDAY)->addWeek();
    }
}
