<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns empty but correctly-shaped responses for frontend pages
 * whose backend endpoints are not yet implemented.
 * This prevents 404 crashes and lets the UI show empty states.
 */
class StubController extends Controller
{
    /** Empty paginated list */
    public function list(): JsonResponse
    {
        return response()->json([
            'data'         => [],
            'current_page' => 1,
            'last_page'    => 1,
            'total'        => 0,
            'from'         => null,
            'to'           => null,
        ]);
    }

    /** Empty single resource */
    public function show(): JsonResponse
    {
        return response()->json(['data' => null]);
    }

    /** Generic success for write operations */
    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Saved.', 'data' => array_merge(['id' => rand(1000, 9999)], $request->all())], 201);
    }

    public function update(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Updated.', 'data' => $request->all()]);
    }

    public function destroy(): JsonResponse
    {
        return response()->json(['message' => 'Deleted.']);
    }

    public function action(): JsonResponse
    {
        return response()->json(['message' => 'Done.']);
    }

    /** Empty summary/stats */
    public function summary(): JsonResponse
    {
        return response()->json(['data' => [], 'summary' => []]);
    }

    /** Template download — returns empty xlsx */
    public function template(): JsonResponse
    {
        return response()->json(['url' => null, 'message' => 'Template not yet available.']);
    }
}
