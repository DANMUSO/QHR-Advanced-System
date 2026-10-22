<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Integration;
use App\Modules\Organization\Models\Webhook;
use App\Modules\Organization\Services\WebhookDispatcher;
use App\Modules\Organization\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Third-party integrations, personal API tokens and outgoing webhooks (permission settings.manage). */
class IntegrationController extends Controller
{
    private const MASK = '••••••••';

    // ---- Integrations ----------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $companyId = CompanyContext::current($request)->id;
        $saved = Integration::withoutGlobalScope('company')->where('company_id', $companyId)->get()->keyBy('key');

        $rows = collect(Integration::CATALOGUE)->map(function ($def, $key) use ($saved) {
            $row = $saved[$key] ?? null;
            $config = $row?->config ?? [];

            return [
                'key' => $key,
                'name' => $def['name'],
                'category' => $def['category'],
                'description' => $def['description'],
                'is_enabled' => (bool) $row?->is_enabled,
                'is_configured' => collect($def['fields'])->keys()->every(fn ($f) => filled($config[$f] ?? null)),
                'fields' => collect($def['fields'])->map(fn ($f, $name) => ['name' => $name, 'label' => $f['label'], 'type' => $f['type'] ?? 'text', 'secret' => (bool) ($f['secret'] ?? false)])->values(),
                'config' => collect($def['fields'])->mapWithKeys(fn ($f, $name) => [
                    $name => ($f['secret'] ?? false) ? (filled($config[$name] ?? null) ? self::MASK : '') : ($config[$name] ?? ''),
                ]),
                'updated_at' => $row?->updated_at,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    public function toggle(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(Integration::CATALOGUE[$key]), 404);
        $integration = $this->record($request, $key);
        $def = Integration::CATALOGUE[$key];

        if (! $integration->is_enabled) {
            $config = $integration->config ?? [];
            $missing = collect($def['fields'])->filter(fn ($f, $name) => blank($config[$name] ?? null))->map(fn ($f) => $f['label'])->values();
            if ($missing->isNotEmpty()) {
                return response()->json(['message' => 'Configure '.$def['name'].' first: '.$missing->implode(', ').' required.'], 422);
            }
        }

        $integration->is_enabled = ! $integration->is_enabled;
        $integration->save();
        $state = $integration->is_enabled ? 'enabled' : 'disabled';
        activity('settings')->causedBy($request->user())->performedOn($integration)->log("Integration {$def['name']} {$state}");

        return response()->json(['message' => "{$def['name']} {$state}.", 'is_enabled' => $integration->is_enabled]);
    }

    public function config(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(Integration::CATALOGUE[$key]), 404);
        $def = Integration::CATALOGUE[$key];

        $rules = [];
        foreach ($def['fields'] as $name => $f) {
            $rules[$name] = match ($f['type'] ?? 'text') {
                'number' => ['nullable', 'numeric', 'min:0', 'max:999999'],
                'email' => ['nullable', 'email', 'max:190'],
                'url' => ['nullable', 'string', 'max:500'],
                default => ['nullable', 'string', 'max:500'],
            };
        }
        $data = $request->validate($rules);

        $integration = $this->record($request, $key);
        $config = $integration->config ?? [];
        foreach ($def['fields'] as $name => $f) {
            if (! array_key_exists($name, $data)) {
                continue;
            }
            $value = $data[$name];
            if (($f['secret'] ?? false) && $value === self::MASK) {
                continue; // unchanged secret
            }
            if (($f['type'] ?? '') === 'url' && filled($value) && ! filter_var($value, FILTER_VALIDATE_URL)) {
                return response()->json(['message' => "{$f['label']} must be a valid URL.", 'errors' => [$name => ["{$f['label']} must be a valid URL."]]], 422);
            }
            $config[$name] = $value === null ? null : (string) $value;
        }
        $integration->config = $config;
        $integration->save();

        activity('settings')->causedBy($request->user())->performedOn($integration)
            ->withProperties(['fields' => array_keys($data)])->log("Integration {$def['name']} configuration updated");

        return response()->json(['message' => "{$def['name']} settings saved."]);
    }

    private function record(Request $request, string $key): Integration
    {
        $companyId = CompanyContext::current($request)->id;
        $row = Integration::withoutGlobalScope('company')->where('company_id', $companyId)->where('key', $key)->first();
        if (! $row) {
            $row = new Integration(['key' => $key, 'is_enabled' => false]);
            $row->company_id = $companyId;
        }

        return $row;
    }

    // ---- API tokens (Sanctum personal access tokens of the signed-in user) ------------------

    public function tokens(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->tokens()->latest()->get(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])]);
    }

    public function createToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);
        $expires = ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;
        $token = $request->user()->createToken($data['name'], ['*'], $expires);

        activity('security')->causedBy($request->user())->performedOn($request->user())->log("API token \"{$data['name']}\" created");

        return response()->json([
            'message' => 'Token created. Copy it now — it will not be shown again.',
            'token' => $token->plainTextToken,
            'data' => $token->accessToken->only(['id', 'name', 'expires_at', 'created_at']),
        ], 201);
    }

    public function revokeToken(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->firstOrFail();
        $token->delete();
        activity('security')->causedBy($request->user())->performedOn($request->user())->log("API token \"{$token->name}\" revoked");

        return response()->json(['message' => 'Token revoked.']);
    }

    // ---- Webhooks --------------------------------------------------------------------------

    public function webhooks(): JsonResponse
    {
        return response()->json(['data' => Webhook::with('company:id,name')->latest()->get(), 'options' => ['events' => Webhook::EVENTS]]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $hook = new Webhook($this->validatedWebhook($request));
        $hook->company_id = CompanyContext::forWrite($request);
        $hook->save();
        activity('settings')->causedBy($request->user())->performedOn($hook)->log("Webhook {$hook->url} added");

        return response()->json(['message' => 'Webhook added.', 'data' => $hook], 201);
    }

    public function updateWebhook(Request $request, int $id): JsonResponse
    {
        $hook = Webhook::findOrFail($id);
        $data = $this->validatedWebhook($request);
        if (! $request->filled('secret')) {
            unset($data['secret']); // keep the existing secret
        }
        $hook->fill($data)->save();
        activity('settings')->causedBy($request->user())->performedOn($hook)->log("Webhook {$hook->url} updated");

        return response()->json(['message' => 'Webhook saved.', 'data' => $hook]);
    }

    public function destroyWebhook(Request $request, int $id): JsonResponse
    {
        $hook = Webhook::findOrFail($id);
        $hook->delete();
        activity('settings')->causedBy($request->user())->performedOn($hook)->log("Webhook {$hook->url} deleted");

        return response()->json(['message' => 'Webhook deleted.']);
    }

    public function testWebhook(int $id): JsonResponse
    {
        $hook = Webhook::findOrFail($id);
        $status = WebhookDispatcher::send($hook, 'ping', ['message' => 'Test delivery from '.config('app.name')]);
        $ok = $status >= 200 && $status < 300;

        return response()->json([
            'message' => $ok ? "Delivered (HTTP {$status})." : ($status ? "Endpoint responded with HTTP {$status}." : 'Could not connect to the endpoint.'),
            'status' => $status,
            'ok' => $ok,
            'data' => $hook->fresh(),
        ]);
    }

    private function validatedWebhook(Request $request): array
    {
        $data = $request->validate([
            'url' => ['required', 'url:https,http', 'max:500'],
            'secret' => ['nullable', 'string', 'min:8', 'max:120'],
            'events' => ['nullable', 'array'],
            'events.*' => [Rule::in(array_keys(Webhook::EVENTS))],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['events'] = array_values($data['events'] ?? []);

        return $data;
    }
}
