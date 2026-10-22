<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Profile photo (JPG/PNG, max 2 MB) and cover image (JPG/PNG, max 2 MB, at least 1200 px wide),
 * for HR (employees.update) and for the employee themself (/profile/...).
 */
class EmployeeMediaController extends Controller
{
    public function upload(Request $request, int $employee, string $kind): JsonResponse
    {
        return $this->store($request, Employee::findOrFail($employee), $kind);
    }

    public function uploadOwn(Request $request, string $kind): JsonResponse
    {
        $e = $request->user()->employee;
        abort_unless($e, 404, 'Your account is not linked to an employee record.');

        return $this->store($request, $e, $kind);
    }

    public function destroy(int $employee, string $kind): JsonResponse
    {
        return $this->remove(Employee::findOrFail($employee), $kind);
    }

    public function destroyOwn(Request $request, string $kind): JsonResponse
    {
        $e = $request->user()->employee;
        abort_unless($e, 404, 'Your account is not linked to an employee record.');

        return $this->remove($e, $kind);
    }

    private function store(Request $request, Employee $e, string $kind): JsonResponse
    {
        $field = $kind === 'cover' ? 'cover' : 'photo';
        $request->validate([
            $field => $kind === 'cover'
                ? ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048', 'dimensions:min_width=1200,min_height=300']
                : ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048', 'dimensions:min_width=100,min_height=100'],
        ], [
            "{$field}.dimensions" => $kind === 'cover' ? 'The cover image must be at least 1200 pixels wide.' : 'The photo must be at least 100×100 pixels.',
            "{$field}.max" => 'The image may not be larger than 2 MB.',
        ]);

        $column = $kind === 'cover' ? 'cover_path' : 'photo_path';
        $old = $e->{$column};
        $path = $request->file($field)->store("employees/{$e->id}", 'public');
        $e->forceFill([$column => $path])->save();
        if ($old && $old !== $path && ! $this->sharedWithOtherRecord($e, $column, $old)) {
            Storage::disk('public')->delete($old);
        }

        return response()->json([
            'photo_url' => $e->photo_url,
            'cover_url' => $e->cover_path ? Storage::disk('public')->url($e->cover_path) : null,
            'message' => $kind === 'cover' ? 'Cover image updated.' : 'Photo updated.',
        ]);
    }

    private function remove(Employee $e, string $kind): JsonResponse
    {
        $column = $kind === 'cover' ? 'cover_path' : 'photo_path';
        if ($old = $e->{$column}) {
            $e->forceFill([$column => null])->save();
            if (! $this->sharedWithOtherRecord($e, $column, $old)) {
                Storage::disk('public')->delete($old);
            }
        }

        return response()->json(['photo_url' => $e->photo_url, 'cover_url' => null, 'message' => 'Image removed.']);
    }

    /** Re-instated / transferred records copy the image path; keep the file while another record uses it. */
    private function sharedWithOtherRecord(Employee $e, string $column, string $path): bool
    {
        return Employee::withoutGlobalScopes()->whereKeyNot($e->id)->where($column, $path)->exists();
    }
}
