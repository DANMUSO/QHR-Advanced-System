<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per company and finished day whose register has been finalised (absentees written).
 * The nightly attendance:compute fills it; reads (register, payroll inputs) finalise any day still missing,
 * so absences no longer depend on the scheduler having run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_finalised_dates')) {
            return;
        }
        Schema::create('attendance_finalised_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('computed_at')->nullable();
            $table->unique(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_finalised_dates');
    }
};
