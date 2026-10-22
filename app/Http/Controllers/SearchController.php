<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Backs the Ctrl+K command palette. Pages/actions are matched client-side; records are matched here. */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $user = $request->user();
        $results = collect();

        $employees = Employee::query()->search($term)->with(['position:id,title', 'department:id,name']);
        if (! $user->can('employees.view')) {
            // Without directory access, people can still find their own direct reports.
            $employees->where('primary_supervisor_id', $user->employee?->id ?? 0);
        }
        foreach ($employees->orderBy('first_name')->limit(8)->get() as $e) {
            $results->push([
                'type' => 'employee',
                'id' => $e->id,
                'title' => $e->full_name,
                'subtitle' => collect([$e->position?->title, $e->department?->name, $e->employee_number])->filter()->implode(' · '),
                'photo_url' => $e->photo_url,
                'status' => $e->status,
                'url' => "/employees/{$e->id}",
            ]);
        }

        foreach (Announcement::visible()->live()->where('title', 'like', "%{$term}%")->limit(4)->get() as $a) {
            $results->push(['type' => 'announcement', 'id' => $a->id, 'title' => $a->title, 'subtitle' => 'Announcement', 'url' => "/announcements#{$a->id}"]);
        }

        return response()->json(['results' => $results]);
    }
}
