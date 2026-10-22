<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded by attendance_geofences (several zones per branch) and shifts (working hours).
        Schema::dropIfExists('attendance_branch_settings');

        Schema::create('attendance_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('start_time', 5);                 // HH:MM
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('grace_minutes')->nullable(); // null = company default
            $table->json('working_days');                   // ISO weekday numbers, 1 = Monday
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('attendance_shifts')->cascadeOnDelete();
            $table->timestamps();
        });

        // Geofenced zones for web / mobile clock-in. branch_id null = applies to the whole company.
        Schema::create('attendance_geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'is_active']);
        });

        // Overtime claims generated from computed days; approved hours feed payroll.
        Schema::create('attendance_overtime', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->nullable()->constrained('attendance_days')->nullOnDelete();
            $table->date('date');
            $table->enum('day_type', ['working', 'weekend', 'holiday'])->default('working');
            $table->unsignedInteger('minutes');
            $table->decimal('rate', 4, 2)->default(1.5);       // pay multiplier
            $table->decimal('amount', 14, 2)->nullable();      // estimate: basic / 225 × rate × hours
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'status', 'date']);
        });

        Schema::create('attendance_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->enum('status', ['submitted', 'approved', 'rejected'])->default('submitted');
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('regular_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'week_start']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_timesheets');
        Schema::dropIfExists('attendance_overtime');
        Schema::dropIfExists('attendance_geofences');
        Schema::dropIfExists('attendance_employee_shifts');
        Schema::dropIfExists('attendance_shifts');
    }
};
