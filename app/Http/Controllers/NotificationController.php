<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = $user->notifications()->latest()->paginate(15);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? 'Notification',
                'message' => $n->data['message'] ?? '',
                'url' => $n->data['url'] ?? null,
                'icon' => $n->data['icon'] ?? 'bell',
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]),
            'unread' => $user->unreadNotifications()->count(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Lightweight poll endpoint for the bell badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->update(['read_at' => now()]);

        return $this->unreadCount($request);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}
