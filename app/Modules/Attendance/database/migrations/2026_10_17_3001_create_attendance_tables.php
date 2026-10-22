<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biometric terminals / kiosks that push punches through the device API.
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('serial', 120)->nullable();
            $table->char('token_hash', 64)->unique();     // sha256 of the bearer token; the plain token is shown once
            $table->string('token_hint', 8)->nullable();  // last characters, to help identify a token
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'active']);
        });

        // Raw clock events. De-duplicated in code (same employee within 60 seconds).
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('punched_at');
            $table->enum('direction', ['in', 'out', 'auto'])->default('auto');
            $table->enum('source', ['device', 'web', 'mobile', 'manual'])->default('device');
            $table->foreignId('device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 1)->nullable();
            $table->unsignedInteger('distance_m')->nullable(); // distance from the branch geofence centre
            $table->string('ip', 45)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delete_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'punched_at']);
            $table->index(['company_id', 'punched_at']);
        });

        // One summary row per employee per day, rebuilt by AttendanceService.
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('day_type', ['working', 'weekend', 'holiday'])->default('working');
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedSmallInteger('punch_count')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);   // check-out − check-in
            $table->unsignedInteger('regular_minutes')->default(0);  // up to the expected end
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('computed_status', 20);
            $table->string('status', 20);                            // override_status ?? computed_status
            $table->string('override_status', 20)->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date', 'status']);
        });

        // Per-branch geofence (web clock-in) and optional working-hours override.
        Schema::create('attendance_branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->nullable();
            $table->boolean('geofence_enabled')->default(false);
            $table->string('work_start', 5)->nullable();
            $table->string('work_end', 5)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_branch_settings');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_devices');
    }
};
