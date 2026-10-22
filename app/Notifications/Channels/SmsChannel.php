<?php

namespace App\Notifications\Channels;

use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * Notification channel: add SmsChannel::class to via() and implement toSms($notifiable): string.
 * The phone number comes from $notifiable->routeNotificationForSms() or the linked employee's phone.
 */
class SmsChannel
{
    public function __construct(private SmsSender $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }
        $phone = method_exists($notifiable, 'routeNotificationForSms')
            ? $notifiable->routeNotificationForSms($notification)
            : ($notifiable->phone ?? null);

        $this->sms->send($phone, $notification->toSms($notifiable), $notifiable->company_id ?? null);
    }
}
