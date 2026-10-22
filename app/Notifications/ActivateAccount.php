<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActivateAccount extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/activate?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]));
        $hours = (int) (config('auth.passwords.activations.expire') / 60);

        return (new MailMessage)
            ->subject('Activate your '.config('app.name').' account')
            ->greeting("Welcome, {$notifiable->name}!")
            ->line('An account has been created for you on the HR portal. Set your password to get started.')
            ->action('Activate account', $url)
            ->line("This link expires in {$hours} hours and can only be used once.");
    }
}
