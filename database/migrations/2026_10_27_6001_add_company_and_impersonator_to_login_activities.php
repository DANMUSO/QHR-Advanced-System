<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in log rows carry the company of the account (so company-scoped views include them even when the
 * account later moves) and, for impersonation events, the administrator who signed in as the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_activities', function (Blueprint $table) {
            if (! Schema::hasColumn('login_activities', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
                $table->index(['company_id', 'created_at']);
            }
            if (! Schema::hasColumn('login_activities', 'impersonator_id')) {
                $table->foreignId('impersonator_id')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            }
        });

        DB::table('login_activities')->whereNull('company_id')->whereNotNull('user_id')->orderBy('id')
            ->chunkById(500, function ($rows) {
                $companies = DB::table('users')->whereIn('id', $rows->pluck('user_id')->unique())->pluck('company_id', 'id');
                foreach ($rows as $row) {
                    if ($companies[$row->user_id] ?? null) {
                        DB::table('login_activities')->where('id', $row->id)->update(['company_id' => $companies[$row->user_id]]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('login_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
