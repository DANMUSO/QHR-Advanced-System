<?php

namespace App\Notifications;

use App\Models\LoginActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceLogin extends Notification
{
    use Queueable;

    public function __construct(public LoginActivity $activity) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->activity;

        return (new MailMessage)
            ->subject('New sign-in to your account')
            ->line("Your account was just signed in from a new device: {$a->browser} on {$a->platform} ({$a->device}).")
            ->line("IP address: {$a->ip_address} — ".$a->created_at->format('d M Y H:i'))
            ->action('Review account security', url('/account/security'))
            ->line('If this was not you, change your password immediately and sign out other sessions.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New sign-in detected',
            'message' => "{$this->activity->browser} on {$this->activity->platform} from {$this->activity->ip_address}",
            'url' => '/account/security',
            'icon' => 'shield-alert',
        ];
    }
}
