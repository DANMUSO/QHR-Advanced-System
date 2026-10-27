<?php

use App\Modules\Employees\Services\EmployeeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix (idempotent): a re-instated or transferred record without a next of kin gets the one kept on its
 * previous record (walking back through earlier periods when the immediate predecessor has none).
 */
return new class extends Migration
{
    public function up(): void
    {
        $successors = DB::table('employees')->whereNotNull('previous_employee_id')
            ->whereNotExists(fn ($q) => $q->from('employee_next_of_kin')->whereColumn('employee_next_of_kin.employee_id', 'employees.id'))
            ->get(['id', 'previous_employee_id']);

        foreach ($successors as $row) {
            $source = $row->previous_employee_id;
            $seen = [];
            while ($source && ! in_array($source, $seen, true)) {
                $seen[] = $source;
                $kin = DB::table('employee_next_of_kin')->where('employee_id', $source)->get();
                if ($kin->isNotEmpty()) {
                    DB::table('employee_next_of_kin')->insert($kin->map(fn ($k) => collect((array) $k)->only(EmployeeService::NOK_FIELDS)->all()
                        + ['employee_id' => $row->id, 'created_at' => now(), 'updated_at' => now()])->all());
                    break;
                }
                $source = DB::table('employees')->where('id', $source)->value('previous_employee_id');
            }
        }
    }

    public function down(): void
    {
        // Data fix only.
    }
};
