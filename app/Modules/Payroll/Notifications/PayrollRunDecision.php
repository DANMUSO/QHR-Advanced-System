<?php

namespace App\Modules\Payroll\Notifications;

use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunDecision extends Notification
{
    use Queueable;

    public function __construct(public PayrollRun $run, public string $decision, public ?string $reason = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Payroll {$this->decision}: {$this->run->title}")
            ->line("{$this->run->title} was {$this->decision}.");
        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail->action('Open run', url('/payroll/runs/'.$this->run->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Payroll '.$this->decision,
            'message' => $this->run->title.($this->reason ? ' – '.$this->reason : ''),
            'url' => '/payroll/runs/'.$this->run->id,
            'icon' => $this->decision === 'approved' ? 'check-circle' : 'x-circle',
        ];
    }
}
