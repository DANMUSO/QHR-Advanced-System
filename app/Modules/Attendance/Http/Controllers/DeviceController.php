<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Attendance\Models\AttendanceDevice;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Biometric terminal registry. Tokens are shown once at creation / rotation and stored hashed. */
class DeviceController extends Controller
{
    public function index(): JsonResponse
    {
        $devices = AttendanceDevice::with('branch:id,name')->orderByDesc('active')->orderBy('name')->get();
        $today = AttendancePunch::whereIn('device_id', $devices->pluck('id'))->whereDate('punched_at', today())
            ->selectRaw('device_id, COUNT(*) as c')->groupBy('device_id')->pluck('c', 'device_id');

        return response()->json(['data' => $devices->map(fn ($d) => $this->present($d) + ['punches_today' => (int) ($today[$d->id] ?? 0)])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $device = new AttendanceDevice($data);
        $device->company_id = $data['branch_id']
            ? Branch::findOrFail($data['branch_id'])->company_id
            : (Tenancy::companyIdForWrite($request->integer('company_id') ?: null) ?? throw ValidationException::withMessages(['branch_id' => 'Choose a branch for this device.']));
        $device->created_by = $request->user()->id;
        $token = $device->issueToken();
        $device->save();

        return response()->json(['message' => 'Device registered. Copy the token now — it will not be shown again.', 'data' => $this->present($device->load('branch:id,name')), 'token' => $token], 201);
    }

    public function update(Request $request, AttendanceDevice $device): JsonResponse
    {
        $data = $this->validated($request);
        if ($data['branch_id']) {
            abort_unless(Branch::whereKey($data['branch_id'])->where('company_id', $device->company_id)->exists(), 422, 'The branch belongs to another company.');
        }
        $device->update($data + ['active' => $request->boolean('active', $device->active)]);

        return response()->json(['message' => 'Device updated.', 'data' => $this->present($device->load('branch:id,name'))]);
    }

    public function rotate(Request $request, AttendanceDevice $device): JsonResponse
    {
        $token = $device->issueToken();
        $device->forceFill(['active' => true, 'revoked_at' => null])->save();
        activity('attendance')->performedOn($device)->causedBy($request->user())->log('Device token rotated');

        return response()->json(['message' => 'New token issued. The old token no longer works.', 'data' => $this->present($device->load('branch:id,name')), 'token' => $token]);
    }

    /** Revokes the device (keeps it, and its punches, for the audit trail). */
    public function destroy(Request $request, AttendanceDevice $device): JsonResponse
    {
        $device->forceFill(['active' => false, 'revoked_at' => now()])->save();

        return response()->json(['message' => 'Device revoked. It can no longer push punches.', 'data' => $this->present($device->load('branch:id,name'))]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'serial' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        return ['name' => $data['name'], 'serial' => $data['serial'] ?? null, 'branch_id' => $data['branch_id'] ?? null];
    }

    private function present(AttendanceDevice $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'serial' => $d->serial,
            'branch_id' => $d->branch_id,
            'branch_name' => $d->branch?->name ?? 'All branches',
            'token_hint' => $d->token_hint ? '••••'.$d->token_hint : null,
            'last_seen_at' => $d->last_seen_at,
            'last_ip' => $d->last_ip,
            'online' => $d->active && $d->last_seen_at && $d->last_seen_at->gt(now()->subMinutes(15)),
            'active' => $d->active,
            'revoked_at' => $d->revoked_at,
            'created_at' => $d->created_at,
        ];
    }
}
