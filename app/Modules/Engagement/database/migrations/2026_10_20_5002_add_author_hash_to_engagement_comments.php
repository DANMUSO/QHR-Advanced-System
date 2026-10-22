<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous comments keep employee_id null. author_hash (HMAC of the user id with the app key) lets the author
 * find their own thread without HR being able to see who wrote it. from_author marks the author's own replies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('engagement_comments', 'author_hash')) {
            Schema::table('engagement_comments', function (Blueprint $t) {
                $t->string('author_hash', 64)->nullable()->after('employee_id')->index();
            });
        }
        if (! Schema::hasColumn('engagement_replies', 'from_author')) {
            Schema::table('engagement_replies', function (Blueprint $t) {
                $t->boolean('from_author')->default(false)->after('body');
            });
        }
    }

    public function down(): void
    {
        Schema::table('engagement_replies', fn (Blueprint $t) => $t->dropColumn('from_author'));
        Schema::table('engagement_comments', function (Blueprint $t) {
            $t->dropIndex(['author_hash']);
            $t->dropColumn('author_hash');
        });
    }
};
