<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->unsignedBigInteger('head_employee_id')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->unsignedBigInteger('head_employee_id')->nullable();
            $table->timestamps();
        });

        Schema::create('employment_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('probation_months')->default(6);
            $table->unsignedSmallInteger('probation_review_days')->default(30);
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employment_level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->boolean('is_supervisor')->default(false);
            $table->timestamps();
        });

        Schema::create('benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete(); // null = applies to all companies
            $table->string('name');
            $table->date('date');
            $table->boolean('recurring')->default(false); // same day every year
            $table->timestamps();
            $table->index('date');
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('employee_number', 40)->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])->nullable();
            $table->string('postal_address')->nullable();
            $table->string('residence')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->text('about')->nullable();
            // Statutory declaration
            $table->string('national_id', 30)->nullable();
            $table->string('kra_pin', 30)->nullable();
            $table->string('nssf_number', 30)->nullable();
            $table->string('shif_number', 30)->nullable();
            $table->string('helb_number', 30)->nullable();
            // Employment
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employment_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('primary_supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('secondary_supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('date_of_joining')->nullable();
            $table->enum('contract_type', ['permanent', 'contract', 'casual', 'intern'])->default('permanent');
            $table->date('contract_end_date')->nullable();
            $table->unsignedTinyInteger('probation_months')->default(0);
            $table->date('probation_end_date')->nullable();
            $table->enum('probation_status', ['none', 'on_probation', 'extended', 'confirmed', 'terminated'])->default('none');
            $table->boolean('has_medical_cover')->default(true);
            $table->enum('status', ['active', 'suspended', 'exited', 'transferred'])->default('active');
            $table->foreignId('previous_employee_id')->nullable()->constrained('employees')->nullOnDelete(); // transfer / re-instatement chain
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_number']);
            $table->index(['company_id', 'status']);
            $table->index('email');
        });

        Schema::table('branches', fn (Blueprint $t) => $t->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete());
        Schema::table('departments', fn (Blueprint $t) => $t->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete());

        Schema::create('employee_next_of_kin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relationship', 40);
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_dependants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('relationship', ['spouse', 'child', 'other']);
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('level', 60);          // e.g. Degree, Diploma, Certificate
            $table->string('title');
            $table->string('institution')->nullable();
            $table->string('grade', 60)->nullable();
            $table->year('year_completed')->nullable();
            $table->timestamps();
        });

        Schema::create('benefit_employee', function (Blueprint $table) {
            $table->foreignId('benefit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->primary(['benefit_id', 'employee_id']);
        });

        Schema::create('employee_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('exit_date');
            $table->enum('reason', [
                'end_of_contract', 'resignation', 'termination', 'death', 'medical_grounds',
                'retrenchment', 'company_closure', 'absconding', 'redundancy', 'retirement',
            ]);
            $table->text('comments')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->primary(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'announcement_reads', 'announcements', 'employee_exits', 'benefit_employee', 'employee_qualifications',
            'employee_dependants', 'employee_next_of_kin',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('branches', fn (Blueprint $t) => $t->dropForeign(['head_employee_id']));
        Schema::table('departments', fn (Blueprint $t) => $t->dropForeign(['head_employee_id']));
        foreach (['employees', 'holidays', 'contact_persons', 'benefits', 'positions', 'employment_levels', 'departments', 'branches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
