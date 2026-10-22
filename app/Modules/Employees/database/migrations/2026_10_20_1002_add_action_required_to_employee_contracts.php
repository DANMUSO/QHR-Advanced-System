<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Expired contracts are flagged for HR (renew or exit) until a new contract is issued or the employee exits. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_contracts', 'action_required_at')) {
            Schema::table('employee_contracts', function (Blueprint $table) {
                $table->timestamp('action_required_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_contracts', 'action_required_at')) {
            Schema::table('employee_contracts', fn (Blueprint $table) => $table->dropColumn('action_required_at'));
        }
    }
};
