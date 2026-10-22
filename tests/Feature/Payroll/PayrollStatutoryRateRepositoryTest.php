<?php

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\Calculation\StatutoryRates;
use App\Modules\Payroll\Services\StatutoryRateRepository;
use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The calculator's rates come from payroll_statutory_rates by effective date, not from hard-coded values. */
class PayrollStatutoryRateRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('payroll_statutory_rates');
        Schema::create('payroll_statutory_rates', function (Blueprint $t) {
            $t->id();
            $t->string('type', 40);
            $t->string('name', 150);
            $t->decimal('lower_limit', 14, 2)->nullable();
            $t->decimal('upper_limit', 14, 2)->nullable();
            $t->decimal('rate', 8, 4)->nullable();
            $t->decimal('employer_rate', 8, 4)->nullable();
            $t->decimal('amount', 14, 2)->nullable();
            $t->decimal('cap', 14, 2)->nullable();
            $t->boolean('tax_deductible')->default(false);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
        });
        PayrollDefaults::seedStatutory();
    }

    public function test_default_rows_switch_nssf_limits_in_february_2026(): void
    {
        $repo = new StatutoryRateRepository;
        $jan = $repo->forDate('2026-01-31');
        $this->assertSame(8000.0, $jan->nssfLowerLimit);
        $this->assertSame(72000.0, $jan->nssfUpperLimit);

        $feb = $repo->forDate('2026-02-28');
        $this->assertSame(9000.0, $feb->nssfLowerLimit);
        $this->assertSame(108000.0, $feb->nssfUpperLimit);
        $this->assertSame(6.0, $feb->nssfRate);
        $this->assertSame(6.0, $feb->nssfEmployerRate);
    }

    public function test_values_are_read_from_the_table(): void
    {
        DB::table('payroll_statutory_rates')->where('type', 'nssf')->whereDate('effective_from', '2026-02-01')->update(['effective_to' => '2026-12-31']);
        DB::table('payroll_statutory_rates')->insert([
            'type' => 'nssf', 'name' => 'Test NSSF', 'lower_limit' => 10000, 'upper_limit' => 120000, 'rate' => 6, 'employer_rate' => 6,
            'tax_deductible' => true, 'effective_from' => '2027-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $r = StatutoryRates::forDate('2027-03-31'); // static entry point delegates to the repository
        $this->assertSame(10000.0, $r->nssfLowerLimit);
        $this->assertSame(120000.0, $r->nssfUpperLimit);
    }

    public function test_seed_is_idempotent(): void
    {
        PayrollDefaults::seedStatutory();
        $this->assertSame(2, DB::table('payroll_statutory_rates')->where('type', 'nssf')->count());
    }
}
