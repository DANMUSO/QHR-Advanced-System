<?php

use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data repair (idempotent):
 *  - standard components get their default GL code (and role, when free) where missing, for every company;
 *  - demo-seeded duplicates of standard components (PERF_BONUS ↔ BONUS, PENSION_E ↔ PEN_EE, PENSION_ER ↔ PEN_ER) are
 *    deactivated when unused by any employee assignment, run item or one-off item; nothing used is touched or deleted;
 *  - an employee's first salary-history row recorded as "increment" from nothing is relabelled "initial".
 */
return new class extends Migration
{
    private const DUPLICATES = [['BONUS', 'PERF_BONUS'], ['PEN_EE', 'PENSION_E'], ['PEN_ER', 'PENSION_ER']];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('payroll_components')) {
            return;
        }
        $used = fn (int $id) => DB::table('payroll_employee_components')->where('component_id', $id)->exists()
            || DB::table('payroll_run_items')->where('component_id', $id)->exists()
            || DB::table('payroll_adjustments')->where('component_id', $id)->exists();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            // Deactivate unused duplicates first, so ensureComponents assigns roles to the component that remains.
            foreach (self::DUPLICATES as [$keep, $duplicate]) {
                $rows = DB::table('payroll_components')->where('company_id', $companyId)->whereIn('code', [$keep, $duplicate])
                    ->where('active', true)->get()->keyBy('code');
                if ($rows->count() < 2) {
                    continue;
                }
                $drop = ! $used($rows[$duplicate]->id) ? $rows[$duplicate] : (! $used($rows[$keep]->id) ? $rows[$keep] : null);
                if ($drop) {
                    DB::table('payroll_components')->where('id', $drop->id)->update(['active' => false, 'updated_at' => now()]);
                }
            }
            PayrollDefaults::ensureComponents((int) $companyId);
        }

        $firsts = DB::table('payroll_salary_history')->selectRaw('min(id) as id')->groupBy('employee_id')->pluck('id');
        DB::table('payroll_salary_history')->whereIn('id', $firsts)->where('reason', 'increment')
            ->where(fn ($q) => $q->whereNull('previous_salary')->orWhere('previous_salary', 0))
            ->update(['reason' => 'initial', 'previous_salary' => null]);
    }

    public function down(): void
    {
        // Data repair only.
    }
};
