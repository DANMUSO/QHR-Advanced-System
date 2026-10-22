<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance payment step for approved expense claims:
 *   approved ──pay (mpesa/bank/cash)──► paid
 *   approved ──pay via payroll──► payroll_pending ──payroll run approved──► paid
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            if (! Schema::hasColumn('expense_claims', 'payment_method')) {
                $table->string('payment_method', 20)->nullable();       // mpesa | bank | cash | payroll
                $table->string('payment_reference', 100)->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('payroll_queued_at')->nullable();
                $table->unsignedBigInteger('payroll_run_id')->nullable(); // no FK: Benefits must not depend on Payroll
            }
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            if (Schema::hasColumn('expense_claims', 'payment_method')) {
                $table->dropConstrainedForeignId('paid_by');
                $table->dropColumn(['payment_method', 'payment_reference', 'payroll_queued_at', 'payroll_run_id']);
            }
        });
    }
};
