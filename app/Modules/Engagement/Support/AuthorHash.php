<?php

namespace App\Modules\Engagement\Support;

use App\Models\User;

/**
 * Links an anonymous submission (engagement comment, grievance) to its author without storing who it is:
 * HMAC-SHA256 of the user id keyed with the app key. HR endpoints never return it.
 */
class AuthorHash
{
    public static function for(User $user): string
    {
        return hash_hmac('sha256', 'author:'.$user->id, (string) config('app.key'));
    }

    /** The author's account, for notifications only (never exposed). Scans the company's users. */
    public static function findUser(?string $hash, ?int $companyId): ?User
    {
        if (! $hash || ! $companyId) {
            return null;
        }

        return User::where('company_id', $companyId)->get(['id', 'name', 'email', 'company_id'])
            ->first(fn (User $u) => hash_equals($hash, self::for($u)));
    }
}
