<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Public applications (CV + cover letter), requisition decisions and offer send / response dates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applicants', function (Blueprint $t) {
            if (! Schema::hasColumn('recruitment_applicants', 'cv_path')) {
                $t->string('cv_path')->nullable();
                $t->text('cover_letter')->nullable();
            }
        });
        Schema::table('recruitment_requisitions', function (Blueprint $t) {
            if (! Schema::hasColumn('recruitment_requisitions', 'decision_note')) {
                $t->string('decision_note', 500)->nullable();
            }
        });
        Schema::table('recruitment_offers', function (Blueprint $t) {
            if (! Schema::hasColumn('recruitment_offers', 'sent_at')) {
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('responded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_applicants', fn (Blueprint $t) => $t->dropColumn(['cv_path', 'cover_letter']));
        Schema::table('recruitment_requisitions', fn (Blueprint $t) => $t->dropColumn('decision_note'));
        Schema::table('recruitment_offers', fn (Blueprint $t) => $t->dropColumn(['sent_at', 'responded_at']));
    }
};
