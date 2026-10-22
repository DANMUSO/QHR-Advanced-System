<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The core `benefits` catalogue only had a name; Benefits Administration needs type, value, eligibility and status.
        Schema::table('benefits', function (Blueprint $t) {
            if (! Schema::hasColumn('benefits', 'type')) {
                $t->string('type', 20)->default('other')->after('name'); // allowance | insurance | pension | leave | other
            }
            if (! Schema::hasColumn('benefits', 'value')) {
                $t->string('value', 100)->nullable()->after('type'); // free text, e.g. "KES 3,000 / month" or "5% of basic"
            }
            if (! Schema::hasColumn('benefits', 'eligibility')) {
                $t->string('eligibility', 20)->default('all')->after('value'); // all | permanent | management | confirmed
            }
            if (! Schema::hasColumn('benefits', 'is_active')) {
                $t->boolean('is_active')->default(true)->after('eligibility');
            }
        });

        if (! Schema::hasTable('benefit_pension_members')) {
            Schema::create('benefit_pension_members', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->string('scheme_type', 10)->default('pension'); // pension | sacco
                $t->string('scheme_name', 150);
                $t->string('member_number', 50)->nullable();
                $t->decimal('employee_contribution', 14, 2)->default(0); // monthly
                $t->decimal('employer_contribution', 14, 2)->default(0); // monthly
                $t->decimal('total_balance', 14, 2)->default(0);
                $t->date('joined_date')->nullable();
                $t->string('status', 20)->default('active'); // active | suspended | exited
                $t->timestamps();
                $t->unique(['employee_id', 'scheme_name']);
                $t->index(['company_id', 'scheme_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_pension_members');
        Schema::table('benefits', function (Blueprint $t) {
            foreach (['type', 'value', 'eligibility', 'is_active'] as $c) {
                if (Schema::hasColumn('benefits', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
