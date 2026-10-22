<?php

namespace App\Modules\Employees\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Self-service request events (profile changes, resignations, letter requests):
 * "submitted" goes to HR, decisions go back to the employee. Mail + bell.
 */
class SelfServiceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public string $url,
        public string $icon = 'user-check',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($this->message)
            ->action('Open', url($this->url));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'icon' => $this->icon];
    }
}
