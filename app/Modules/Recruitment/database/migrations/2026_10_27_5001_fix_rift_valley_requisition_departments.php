<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA #13: the demo seeder gave Rift Valley (company 2) requisitions a random department. Positions carry no department,
 * so the correct one is taken from the staff who hold that position — only when they all sit in one department.
 * The requisition's postings follow it. Idempotent; data-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $company = DB::table('companies')->where('name', 'Rift Valley Agribusiness Co.')->value('id');
        if (! $company) {
            return;
        }

        foreach (DB::table('recruitment_requisitions')->where('company_id', $company)->whereNull('deleted_at')->get() as $req) {
            $depts = DB::table('employees')->where('company_id', $company)->where('position_id', $req->position_id)
                ->whereNull('deleted_at')->whereNotNull('department_id')->distinct()->pluck('department_id');
            if ($depts->count() !== 1 || (int) $depts->first() === (int) $req->department_id) {
                continue;
            }
            DB::table('recruitment_requisitions')->where('id', $req->id)->update(['department_id' => $depts->first(), 'updated_at' => now()]);
            DB::table('recruitment_postings')->where('requisition_id', $req->id)->update(['department_id' => $depts->first(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Data correction; not reversed.
    }
};
