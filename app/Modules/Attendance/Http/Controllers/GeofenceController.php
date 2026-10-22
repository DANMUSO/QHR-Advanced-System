<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Attendance\Models\AttendanceGeofence;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Geofenced zones for web / mobile clock-in. A branch with active zones requires staff to be inside one. */
class GeofenceController extends Controller
{
    public function index(): JsonResponse
    {
        $zones = AttendanceGeofence::with('branch:id,name')->orderBy('name')->get();

        return response()->json(['data' => $zones->map(fn ($z) => $this->present($z))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $zone = new AttendanceGeofence($data);
        $zone->company_id = $this->companyFor($request, $data['branch_id'] ?? null);
        $zone->save();

        return response()->json(['message' => 'Zone created.', 'data' => $this->present($zone->load('branch:id,name'))], 201);
    }

    public function update(Request $request, AttendanceGeofence $geofence): JsonResponse
    {
        $data = $this->validated($request);
        if (! empty($data['branch_id'])) {
            abort_unless(Branch::whereKey($data['branch_id'])->where('company_id', $geofence->company_id)->exists(), 422, 'The branch belongs to another company.');
        }
        $geofence->update($data);

        return response()->json(['message' => 'Zone updated.', 'data' => $this->present($geofence->load('branch:id,name'))]);
    }

    public function destroy(AttendanceGeofence $geofence): JsonResponse
    {
        $geofence->delete();

        return response()->json(['message' => 'Zone deleted.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required_without:radius_m', 'nullable', 'integer', 'min:20', 'max:20000'],
            'radius_m' => ['nullable', 'integer', 'min:20', 'max:20000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['boolean'],
        ]);

        return [
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'radius_m' => (int) ($data['radius_meters'] ?? $data['radius_m']),
            'branch_id' => $data['branch_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function companyFor(Request $request, ?int $branchId): int
    {
        if ($branchId) {
            $branch = Branch::find($branchId);
            abort_unless($branch, 422, 'Unknown branch.');

            return $branch->company_id;
        }

        return Tenancy::companyIdForWrite($request->integer('company_id') ?: null)
            ?? throw ValidationException::withMessages(['branch_id' => 'Choose a branch (or select a company) for this zone.']);
    }

    private function present(AttendanceGeofence $z): array
    {
        return [
            'id' => $z->id,
            'company_id' => $z->company_id,
            'name' => $z->name,
            'address' => $z->address,
            'latitude' => $z->latitude,
            'longitude' => $z->longitude,
            'radius_meters' => $z->radius_m,
            'branch_id' => $z->branch_id,
            'branch_name' => $z->branch?->name ?? 'All branches',
            'is_active' => $z->is_active,
        ];
    }
}
