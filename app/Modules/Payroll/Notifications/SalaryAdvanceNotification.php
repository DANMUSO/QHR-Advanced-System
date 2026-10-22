<?php

namespace App\Modules\Payroll\Notifications;

use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** event: requested (to payroll officers / approvers) | approved | rejected (to the employee) */
class SalaryAdvanceNotification extends Notification
{
    use Queueable;

    public function __construct(public PayrollSalaryAdvance $advance, public string $event) {}

    public function via(object $notifiable): array
    {
        return $this->event === 'requested' ? ['mail', 'database'] : ['mail', 'database', SmsChannel::class];
    }

    private function text(): string
    {
        $a = $this->advance;
        $amount = 'KES '.number_format($a->amount, 2);
        $months = max(1, (int) ($a->repayment_months ?: 1));
        $plan = $months > 1 ? " over {$months} months" : '';

        return match ($this->event) {
            'requested' => ($a->employee?->full_name ?? 'An employee')." requested a salary advance of {$amount}, repayable{$plan}".($months > 1 ? '' : ' in one month').'.',
            'approved' => "Your salary advance of {$amount} was approved. Recovery starts in the ".($a->recovery_period?->format('F Y') ?? 'next')." payroll{$plan}.",
            default => "Your salary advance request of {$amount} was declined.".($a->decision_note ? ' Reason: '.$a->decision_note : ''),
        };
    }

    private function link(): string
    {
        return $this->event === 'requested' ? '/payroll?tab=advances' : '/portal';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Salary advance '.$this->event)->line($this->text())->action('Open', url($this->link()));
    }

    public function toSms(): string
    {
        return $this->text();
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Salary advance '.$this->event,
            'message' => $this->text(),
            'url' => $this->link(),
            'icon' => 'hand-coins',
        ];
    }
}
