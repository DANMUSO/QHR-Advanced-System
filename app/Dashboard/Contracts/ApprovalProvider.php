<?php

namespace App\Dashboard\Contracts;

use App\Models\User;

interface ApprovalProvider
{
    /**
     * Items awaiting this user's decision. Each item:
     * ['id', 'type' (e.g. 'leave'), 'title', 'subtitle', 'requested_at', 'url',
     *  'approve_endpoint' (nullable), 'reject_endpoint' (nullable)]
     */
    public function pendingFor(User $user, int $limit = 10): array;

    public function countFor(User $user): int;
}
