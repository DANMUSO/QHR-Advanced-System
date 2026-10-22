<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Salary structures / scales (e.g. per grade) with default components applied to employees.
        Schema::create('payroll_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->foreignId('employment_level_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('min_salary', 14, 2)->nullable();
            $table->decimal('max_salary', 14, 2)->nullable();
            $table->decimal('basic_salary', 14, 2)->default(0); // default / mid-point basic
            $table->json('components')->nullable();             // [{component_id, amount, rate}]
            $table->string('description', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Pay calendar: one row per company per month with cut-off and pay date.
        Schema::create('payroll_calendar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->date('cutoff_date');
            $table->date('pay_date');
            $table->string('status', 20)->default('scheduled'); // scheduled | processing | approved | paid
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'period']);
        });

        // Log of generated bank / M-Pesa payment files.
        Schema::create('payroll_payment_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                         // bank | mpesa | cash
            $table->string('provider')->nullable();
            $table->unsignedInteger('records')->default(0);
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->string('filename');
            $table->string('status', 20)->default('generated'); // generated | sent
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });

        Schema::table('payroll_pay_profiles', function (Blueprint $table) {
            $table->foreignId('structure_id')->nullable()->after('employee_id')->constrained('payroll_structures')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_pay_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('structure_id');
        });
        Schema::dropIfExists('payroll_payment_files');
        Schema::dropIfExists('payroll_calendar');
        Schema::dropIfExists('payroll_structures');
    }
};
