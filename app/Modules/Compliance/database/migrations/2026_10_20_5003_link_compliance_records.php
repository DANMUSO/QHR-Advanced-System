<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - comp_disciplinary_cases.pip_id: a case opened when a PIP is closed as escalated.
 * - comp_warnings.letter_id: the Employees "warning" letter generated for the warning.
 * - comp_grievances: self-service fields (subject, anonymous + author_hash; employee_id is null when anonymous).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('comp_disciplinary_cases', 'pip_id')) {
            Schema::table('comp_disciplinary_cases', function (Blueprint $t) {
                $t->foreignId('pip_id')->nullable()->after('employee_id')->constrained('perf_pips')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('comp_warnings', 'letter_id')) {
            Schema::table('comp_warnings', function (Blueprint $t) {
                $t->foreignId('letter_id')->nullable()->after('disciplinary_case_id')->constrained('employee_letters')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('comp_grievances', 'subject')) {
            Schema::table('comp_grievances', function (Blueprint $t) {
                $t->string('subject', 200)->nullable()->after('category');
                $t->boolean('anonymous')->default(false)->after('subject');
                $t->string('author_hash', 64)->nullable()->after('anonymous')->index();
            });
            Schema::table('comp_grievances', function (Blueprint $t) {
                $t->unsignedBigInteger('employee_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('comp_grievances', function (Blueprint $t) {
            $t->dropIndex(['author_hash']);
            $t->dropColumn(['subject', 'anonymous', 'author_hash']);
        });
        Schema::table('comp_warnings', fn (Blueprint $t) => $t->dropConstrainedForeignId('letter_id'));
        Schema::table('comp_disciplinary_cases', fn (Blueprint $t) => $t->dropConstrainedForeignId('pip_id'));
    }
};
