<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_exits', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE employee_exits SET company_id = (SELECT e.company_id FROM employees e WHERE e.id = employee_exits.employee_id)');

        Schema::table('employee_exits', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_exits', function (Blueprint $t) {
            $t->dropConstrainedForeignId('company_id');
        });
    }
};
