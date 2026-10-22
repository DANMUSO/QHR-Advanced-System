<?php

namespace App\Http\Controllers;

use App\Dashboard\Widget;
use App\Dashboard\WidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private WidgetRegistry $registry) {}

    /** All widgets the user may see, ordered by their saved layout; hidden widgets are listed but not computed. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $layout = $user->preference('dashboard', []);
        $order = $layout['order'] ?? [];
        $hidden = $layout['hidden'] ?? [];

        $widgets = $this->registry->visibleTo($user)
            ->sortBy(fn (Widget $w, $i) => ($pos = array_search($w->key(), $order, true)) === false ? 1000 + $i : $pos)
            ->values()
            ->map(fn (Widget $w) => [
                'key' => $w->key(),
                'title' => $w->title(),
                'size' => $w->size(),
                'hidden' => in_array($w->key(), $hidden, true),
                'data' => in_array($w->key(), $hidden, true) ? null : $w->data($user),
            ]);

        return response()->json(['widgets' => $widgets, 'generated_at' => now()]);
    }

    /** Refresh a single widget (used for auto-refresh and after un-hiding). */
    public function show(Request $request, string $key): JsonResponse
    {
        $widget = $this->registry->find($key);
        abort_unless($widget && $widget->visible($request->user()), 404);

        return response()->json(['key' => $key, 'data' => $widget->data($request->user())]);
    }
}
