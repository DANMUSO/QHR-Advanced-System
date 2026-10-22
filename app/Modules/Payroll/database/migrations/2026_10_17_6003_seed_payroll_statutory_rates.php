<?php

use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Statutory rates are reference data the engine cannot run without, so they ship with the schema. */
    public function up(): void
    {
        PayrollDefaults::seedStatutory();
    }

    public function down(): void
    {
        DB::table('payroll_statutory_rates')->delete();
    }
};
