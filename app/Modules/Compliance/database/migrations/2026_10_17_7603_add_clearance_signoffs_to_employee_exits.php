<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who signed off each exit clearance item, and when: {item_key: {user_id, name, at}}. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_exits', 'clearance_signoffs')) {
            Schema::table('employee_exits', function (Blueprint $table) {
                $table->json('clearance_signoffs')->nullable()->after('clearance');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_exits', 'clearance_signoffs')) {
            Schema::table('employee_exits', fn (Blueprint $table) => $table->dropColumn('clearance_signoffs'));
        }
    }
};
