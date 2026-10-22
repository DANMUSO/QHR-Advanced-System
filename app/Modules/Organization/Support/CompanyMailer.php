<?php

namespace App\Modules\Organization\Support;

use App\Modules\Organization\Models\Integration;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;

/**
 * Per-company SMTP (Settings → Integrations → Company SMTP mail).
 * While a mail notification is sent to a user of a company with the integration enabled, the default mailer
 * is switched to a mailer built from that company's settings, then restored. Other mail uses config/mail.php.
 */
class CompanyMailer
{
    private static ?string $previous = null;

    public static function register(): void
    {
        Event::listen(NotificationSending::class, function (NotificationSending $e) {
            if ($e->channel !== 'mail' || ! ($companyId = $e->notifiable->company_id ?? null)) {
                return;
            }
            if ($name = static::mailerFor((int) $companyId)) {
                static::$previous = config('mail.default');
                config(['mail.default' => $name]);
            }
        });

        $restore = function ($e) {
            if ($e->channel === 'mail' && static::$previous !== null) {
                config(['mail.default' => static::$previous]);
                static::$previous = null;
            }
        };
        Event::listen(NotificationSent::class, $restore);
        Event::listen(NotificationFailed::class, $restore);
    }

    /** Registers (once per configuration) and returns the company's mailer name, or null when not configured. */
    public static function mailerFor(int $companyId): ?string
    {
        // Local / test runs that log mail keep logging, even when a company has SMTP configured.
        if (app()->environment('local', 'testing') && in_array(config('mail.default'), ['log', 'array'], true)) {
            return null;
        }
        $cfg = Integration::configFor($companyId, 'smtp');
        if (! $cfg || blank($cfg['host'] ?? null) || blank($cfg['from_address'] ?? null)) {
            return null;
        }

        // Name includes a hash so a long-running queue worker picks up changed settings.
        $name = 'company_'.$companyId.'_'.substr(md5(json_encode($cfg)), 0, 8);
        if (! config("mail.mailers.{$name}")) {
            config(["mail.mailers.{$name}" => [
                'transport' => 'smtp',
                'scheme' => strtolower((string) ($cfg['encryption'] ?? '')) === 'ssl' ? 'smtps' : 'smtp',
                'host' => $cfg['host'],
                'port' => (int) ($cfg['port'] ?? 587) ?: 587,
                'username' => $cfg['username'] ?? null,
                'password' => $cfg['password'] ?? null,
                'timeout' => 15,
                'from' => ['address' => $cfg['from_address'], 'name' => config('mail.from.name')],
            ]]);
        }

        return $name;
    }
}
