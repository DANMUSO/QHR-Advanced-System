<?php

namespace Tests\Feature\Payroll;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Payroll\LeavePayrollInputs;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Final-dues leave settlement (QA, Jane Achieng: joined 1 Sep, leaving 20 Nov, 3 days taken).
 * Leave is accrued to the last working day with the exit month pro-rated, then netted against leave taken:
 * positive → encashed, still negative after full accrual → recovered.
 */
class PayrollLeaveSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        LeaveType::seedDefaults($this->company);
        $this->officer = User::create(['company_id' => $this->company->id, 'name' => 'Olive', 'email' => 'olive@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $this->officer->assignRole('payroll-officer');
    }

    /** Ledger as of 22 Oct: Sep opening + Oct accrual (3.5 days), minus the leave taken. */
    private function leaver(float $taken): Employee
    {
        $e = new Employee(['first_name' => 'Jane', 'last_name' => 'Achieng', 'phone' => '0712345678', 'employee_number' => uniqid('E'), 'kra_pin' => 'A001234567Z']);
        $e->company_id = $this->company->id;
        $e->status = 'exited';
        $e->gender = 'female';
        $e->date_of_joining = '2026-09-01';
        $e->save();
        PayrollPayProfile::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id,
            'basic_salary' => 60000, 'payment_method' => 'cash', 'on_hold' => true]);
        PayrollSalaryHistory::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id,
            'basic_salary' => 60000, 'effective_date' => '2026-09-01', 'reason' => 'initial']);
        DB::table('employee_exits')->insert(['company_id' => $this->company->id, 'employee_id' => $e->id, 'exit_date' => '2026-11-20',
            'reason' => 'resignation', 'created_at' => now(), 'updated_at' => now()]);

        $annual = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'ANNUAL')->first();
        $balances = app(LeaveBalanceService::class);
        $balance = $balances->ensure($e, $annual, 2026, Carbon::parse('2026-10-22'));
        $balances->post($balance, 'deduction', -$taken, 'Leave taken', reference: 'test:taken');

        return $e->fresh();
    }

    private function finalDuesItems(Employee $leaver): \Illuminate\Support\Collection
    {
        $run = $this->actingAs($this->officer)->postJson('/api/payroll/runs', ['period' => '2026-11', 'type' => 'off_cycle',
            'off_cycle_reason' => 'final_dues', 'employee_id' => $leaver->id, 'calculate' => true])->assertCreated()->json('data');

        return PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run['id'])->where('employee_id', $leaver->id)
            ->firstOrFail()->items()->get()->keyBy('code');
    }

    public function test_leave_is_accrued_to_the_exit_date_and_the_positive_remainder_encashed(): void
    {
        $leaver = $this->leaver(3);

        $settlement = app(LeavePayrollInputs::class)->inputsFor($leaver, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))['annual_leave_settlement'];
        $this->assertEqualsWithDelta(1.67, $settlement, 0.001, 'Sep 1.75 + Oct 1.75 + Nov 1.75 × 20/30 = 4.67 earned − 3 taken');

        $items = $this->finalDuesItems($leaver);
        $this->assertEqualsWithDelta(round(60000 / 22 * 1.67, 2), $items['LEAVE_ENC']->amount, 0.01);
        $this->assertArrayNotHasKey('LEAVE_REC', $items->all());
    }

    public function test_leave_still_overtaken_after_full_accrual_is_recovered(): void
    {
        $items = $this->finalDuesItems($this->leaver(6)); // 4.67 earned − 6 taken = −1.33

        $this->assertArrayNotHasKey('LEAVE_ENC', $items->all());
        $this->assertEqualsWithDelta(round(60000 / 22 * 1.33, 2), $items['LEAVE_REC']->amount, 0.01);
    }

    public function test_employees_without_an_exit_get_no_settlement(): void
    {
        $leaver = $this->leaver(3);
        DB::table('employee_exits')->where('employee_id', $leaver->id)->update(['status' => 'cancelled']);

        $inputs = app(LeavePayrollInputs::class)->inputsFor($leaver, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertArrayNotHasKey('annual_leave_settlement', $inputs);
    }
}
