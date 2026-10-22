<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('probation_end_date');
            $table->enum('status', ['pending', 'primary_done', 'secondary_done', 'hoo_done', 'hr_done', 'confirmed', 'extended', 'terminated'])->default('pending');
            // Primary supervisor
            $table->text('primary_comment')->nullable();
            $table->foreignId('primary_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('primary_reviewed_at')->nullable();
            // Secondary supervisor
            $table->text('secondary_comment')->nullable();
            $table->foreignId('secondary_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('secondary_reviewed_at')->nullable();
            // Head of Operations
            $table->text('hoo_comment')->nullable();
            $table->foreignId('hoo_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hoo_reviewed_at')->nullable();
            // HR final decision
            $table->enum('decision', ['confirm', 'extend', 'terminate'])->nullable();
            $table->text('hr_comment')->nullable();
            $table->date('extension_end_date')->nullable();
            $table->foreignId('hr_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probation_reviews');
    }
};
