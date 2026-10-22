<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Employee who owes the primary (supervisor) review, resolved up the reporting line (ApproverResolver). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('probation_reviews', 'primary_approver_id')) {
            Schema::table('probation_reviews', function (Blueprint $table) {
                $table->foreignId('primary_approver_id')->nullable()->after('status')->constrained('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('probation_reviews', 'primary_approver_id')) {
            Schema::table('probation_reviews', fn (Blueprint $table) => $table->dropConstrainedForeignId('primary_approver_id'));
        }
    }
};
