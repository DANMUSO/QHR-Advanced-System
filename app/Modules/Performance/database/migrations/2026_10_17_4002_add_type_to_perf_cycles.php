<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perf_cycles', function (Blueprint $t) {
            $t->string('type', 20)->default('annual')->after('name'); // annual | mid_year | quarterly | probation
        });
    }

    public function down(): void
    {
        Schema::table('perf_cycles', fn (Blueprint $t) => $t->dropColumn('type'));
    }
};
