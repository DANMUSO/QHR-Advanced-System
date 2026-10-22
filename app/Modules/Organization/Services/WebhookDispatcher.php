<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends signed JSON events to a company's webhooks.
 * Body: {event, company_id, sent_at, data}. Header X-HR-Signature: sha256=<HMAC of body with the webhook secret>.
 */
class WebhookDispatcher
{
    /** Queue an event for every active webhook subscribed to it; delivery happens after the HTTP response is sent. */
    public static function dispatch(?int $companyId, string $event, array $data): void
    {
        if (! $companyId || app()->runningInConsole()) {
            return; // seeders, imports from the CLI, scheduled jobs: no outbound calls
        }

        $hooks = Webhook::withoutGlobalScope('company')->where('company_id', $companyId)->where('is_active', true)->get()
            ->filter(fn (Webhook $h) => empty($h->events) || in_array($event, $h->events, true));

        if ($hooks->isEmpty()) {
            return;
        }

        app()->terminating(function () use ($hooks, $event, $data) {
            foreach ($hooks as $hook) {
                static::send($hook, $event, $data);
            }
        });
    }

    /** Delivers one event synchronously and records the result. Returns the HTTP status (0 = connection failure). */
    public static function send(Webhook $hook, string $event, array $data): int
    {
        $body = json_encode([
            'event' => $event,
            'company_id' => $hook->company_id,
            'sent_at' => now()->toIso8601String(),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);

        $headers = ['X-HR-Event' => $event, 'User-Agent' => config('app.name').'-Webhooks/1.0'];
        if ($hook->secret) {
            $headers['X-HR-Signature'] = 'sha256='.hash_hmac('sha256', $body, $hook->secret);
        }

        try {
            $status = Http::timeout(5)->connectTimeout(3)->withHeaders($headers)->withBody($body, 'application/json')->post($hook->url)->status();
        } catch (Throwable) {
            $status = 0;
        }

        $hook->forceFill(['last_triggered_at' => now(), 'last_status' => $status])->saveQuietly();

        return $status;
    }
}
