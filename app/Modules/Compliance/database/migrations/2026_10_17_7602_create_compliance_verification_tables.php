<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comp_background_checks')) {
            Schema::create('comp_background_checks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->string('type', 30)->default('good_conduct'); // good_conduct | crb_check | police_clearance
                $t->string('reference_number', 80)->nullable();
                $t->date('issue_date')->nullable();
                $t->date('expiry_date')->nullable();
                $t->string('status', 20)->default('pending'); // pending | clear | flagged | expired
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('comp_medical_covers')) {
            Schema::create('comp_medical_covers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->string('scheme_name', 150);
                $t->string('member_number', 80)->nullable();
                $t->decimal('cover_limit', 14, 2)->nullable();
                $t->date('issue_date')->nullable();
                $t->date('expiry_date')->nullable();
                $t->string('status', 20)->default('active'); // active | pending | expired
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_medical_covers');
        Schema::dropIfExists('comp_background_checks');
    }
};
