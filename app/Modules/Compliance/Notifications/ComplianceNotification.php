<?php

namespace App\Modules\Compliance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Grievance, disciplinary and warning-letter notices (mail + in-app bell). */
class ComplianceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public string $url = '/compliance',
        public string $icon = 'shield',
        public bool $mail = true,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->mail ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($this->message)
            ->action('Open HR Advanced', rtrim(config('app.url'), '/').$this->url);
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'icon' => $this->icon];
    }
}
