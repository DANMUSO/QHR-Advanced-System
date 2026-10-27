<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Receipt file for an expense claim (private "local" disk, served through an authorised download). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            if (! Schema::hasColumn('expense_claims', 'receipt_path')) {
                $table->string('receipt_path')->nullable();
                $table->string('receipt_name', 200)->nullable();
                $table->string('receipt_mime', 100)->nullable();
                $table->unsignedInteger('receipt_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            if (Schema::hasColumn('expense_claims', 'receipt_path')) {
                $table->dropColumn(['receipt_path', 'receipt_name', 'receipt_mime', 'receipt_size']);
            }
        });
    }
};
