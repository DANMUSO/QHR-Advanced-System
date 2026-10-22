<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\Announcement;
use App\Models\User;

class AnnouncementsWidget extends Widget
{
    public function key(): string
    {
        return 'announcements';
    }

    public function title(): string
    {
        return 'Announcements';
    }

    public function size(): string
    {
        return 'md';
    }

    public function data(User $user): array
    {
        $items = Announcement::visible()->live()->with('author:id,name')
            ->withExists(['readers as is_read' => fn ($q) => $q->where('users.id', $user->id)])
            ->orderByDesc('pinned')->orderByDesc('published_at')->limit(5)->get();

        return [
            'unread' => Announcement::visible()->live()
                ->whereDoesntHave('readers', fn ($q) => $q->where('users.id', $user->id))->count(),
            'items' => $items->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'excerpt' => str($a->body)->stripTags()->limit(160)->toString(),
                'body' => $a->body,
                'pinned' => $a->pinned,
                'author' => $a->author?->name,
                'published_at' => $a->published_at,
                'is_read' => (bool) $a->is_read,
            ]),
        ];
    }
}
