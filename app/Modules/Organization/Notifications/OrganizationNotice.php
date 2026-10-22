<?php

namespace App\Modules\Organization\Notifications;

use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Generic Organization-module notice (reminders, support ticket updates). channel: email | sms | in_app */
class OrganizationNotice extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public string $url = '/',
        public string $channel = 'in_app',
        public string $icon = 'bell',
    ) {}

    public function via(object $notifiable): array
    {
        return match ($this->channel) {
            'email' => ['database', 'mail'],
            'sms' => ['database', SmsChannel::class],
            default => ['database'],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message)
            ->action('Open HR portal', url($this->url));
    }

    public function toSms(object $notifiable): string
    {
        return $this->title.': '.$this->message;
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'icon' => $this->icon];
    }
}
