<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Announcement::visible()->with('author:id,name')
            ->withExists(['readers as is_read' => fn ($q) => $q->where('users.id', $user->id)])
            ->withCount('readers')
            ->orderByDesc('pinned')->orderByDesc('published_at');

        if (! $user->can('announcements.manage')) {
            $query->live();
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $announcement = new Announcement($this->validated($request));
        $announcement->author_id = $request->user()->id;
        $announcement->company_id = $request->boolean('all_companies') && Tenancy::isGlobal()
            ? null : Tenancy::companyIdForWrite($request->integer('company_id') ?: null);
        $announcement->save();

        return response()->json($announcement, 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $this->authorizeCompany($announcement);
        $announcement->update($this->validated($request));

        return response()->json($announcement);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        $this->authorizeCompany($announcement);
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    public function markRead(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->readers()->syncWithoutDetaching([$request->user()->id => ['read_at' => now()]]);

        return response()->json(['message' => 'Marked as read.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'pinned' => ['boolean'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ]);
        $data['published_at'] ??= now();

        return $data;
    }

    private function authorizeCompany(Announcement $announcement): void
    {
        abort_unless(Tenancy::isGlobal() || $announcement->company_id === Tenancy::companyId(), 403);
    }
}
