<?php

namespace App\Services\Sms;

use App\Modules\Organization\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends SMS through Africa's Talking (the company's own account when configured under Integrations,
 * else the platform account in config/services.php), or writes to the log when SMS_DRIVER=log (default).
 * Kenyan numbers are normalised: 07XXXXXXXX / 01XXXXXXXX -> +2547XXXXXXXX / +2541XXXXXXXX.
 */
class SmsSender
{
    /** $companyId selects the company's own Africa's Talking account (Settings → Integrations) when enabled. */
    public function send(?string $phone, string $message, ?int $companyId = null): bool
    {
        $to = static::normalise($phone);
        if (! $to) {
            return false;
        }

        $creds = $this->credentials($companyId ?? auth()->user()?->company_id);
        if (! $creds) {
            Log::info("[SMS to {$to}] {$message}");

            return true;
        }

        // Notifications are sent inside the user's request: a slow gateway must not hold up a leave submission.
        $response = Http::asForm()->connectTimeout(5)->timeout(10)->withHeaders([
            'apiKey' => $creds['key'],
            'Accept' => 'application/json',
        ])->post(config('services.sms.africastalking.endpoint'), array_filter([
            'username' => $creds['username'],
            'to' => $to,
            'message' => $message,
            'from' => $creds['from'],
        ]));

        if ($response->failed()) {
            Log::warning('SMS delivery failed', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);
        }

        return $response->successful();
    }

    /**
     * Africa's Talking credentials: the company's enabled integration first, then config('services.sms').
     * Null means "write to the log": SMS_DRIVER=log with no company account, or any local/testing run with SMS_DRIVER=log.
     */
    private function credentials(?int $companyId): ?array
    {
        $logOnly = config('services.sms.driver') !== 'africastalking';
        if ($logOnly && app()->environment('local', 'testing')) {
            return null;
        }

        $company = $companyId ? Integration::configFor($companyId, 'sms_africastalking') : null;
        if ($company && filled($company['username'] ?? null) && filled($company['api_key'] ?? null)) {
            return ['username' => $company['username'], 'key' => $company['api_key'], 'from' => $company['sender_id'] ?? null];
        }
        if ($logOnly) {
            return null;
        }

        return [
            'username' => config('services.sms.africastalking.username'),
            'key' => config('services.sms.africastalking.key'),
            'from' => config('services.sms.africastalking.from'),
        ];
    }

    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+254'.substr($digits, 1);
        }
        if (str_starts_with($digits, '254')) {
            return '+'.$digits;
        }
        if (strlen($digits) === 9 && in_array($digits[0], ['7', '1'], true)) {
            return '+254'.$digits;
        }

        return '+'.$digits;
    }
}
