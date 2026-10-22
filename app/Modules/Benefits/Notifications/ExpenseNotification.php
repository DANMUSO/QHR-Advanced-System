<?php

namespace App\Modules\Benefits\Notifications;

use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Models\TravelRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Expense claim and travel request workflow steps:
 *   submitted         → approver (supervisor, or HR fallback)     mail + bell
 *   awaiting_payment  → Finance (claims.pay)                      mail + bell   (expenses only)
 *   approved          → employee                                  mail + bell
 *   rejected          → employee (with reason)                    mail + bell
 *   payroll_pending   → employee ("paid with your next salary")   bell          (expenses only)
 *   paid              → employee                                  mail + bell   (expenses only)
 */
class ExpenseNotification extends Notification
{
    use Queueable;

    public function __construct(public ExpenseClaim|TravelRequest $item, public string $event) {}

    public function via(object $notifiable): array
    {
        return $this->event === 'payroll_pending' ? ['database'] : ['mail', 'database'];
    }

    private function isTravel(): bool
    {
        return $this->item instanceof TravelRequest;
    }

    private function kind(): string
    {
        return $this->isTravel() ? 'travel request' : 'expense claim';
    }

    private function subject(): string
    {
        $i = $this->item;

        return $this->isTravel()
            ? "{$i->destination} ({$i->departure_date?->format('d M')} – {$i->return_date?->format('d M Y')})"
            : "\"{$i->description}\"";
    }

    private function amount(): string
    {
        return 'KES '.number_format((float) ($this->isTravel() ? $this->item->estimated_cost : $this->item->amount), 2);
    }

    private function url(): string
    {
        return match ($this->event) {
            'submitted' => '/expenses/approvals'.($this->isTravel() ? '?tab=travel' : ''),
            'awaiting_payment' => '/expenses/payments',
            default => '/portal',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->item->employee?->full_name ?? 'An employee';
        $mail = (new MailMessage)->greeting('Hello '.($notifiable->name ?? '').',');

        return match ($this->event) {
            'submitted' => $mail->subject(ucfirst($this->kind()).' awaiting your approval')
                ->line("{$name} submitted a {$this->kind()}: {$this->subject()}, {$this->amount()}.")
                ->action('Review', url($this->url())),
            'awaiting_payment' => $mail->subject('Expense claim ready for payment')
                ->line("An expense claim by {$name} ({$this->subject()}, {$this->amount()}) was approved and is awaiting payment.")
                ->line('Pay it directly (M-Pesa, bank or cash) or through the next payroll.')
                ->action('Open expense payments', url($this->url())),
            'rejected' => $mail->subject('Your '.$this->kind().' was rejected')
                ->line("Your {$this->kind()} {$this->subject()} ({$this->amount()}) was rejected.")
                ->line('Reason: '.($this->item->decision_note ?: 'not given')),
            'paid' => $mail->subject('Your expense claim was paid')
                ->line("Your expense claim {$this->subject()} has been paid: {$this->amount()} via "
                    .(ExpenseClaim::PAYMENT_METHODS[$this->item->payment_method] ?? $this->item->payment_method)
                    .($this->item->payment_reference ? " (ref {$this->item->payment_reference})" : '').'.'),
            default => $mail->subject('Your '.$this->kind().' was approved')
                ->line("Your {$this->kind()} {$this->subject()} ({$this->amount()}) was approved.")
                ->when(! $this->isTravel(), fn ($m) => $m->line('Finance will process the payment.')),
        };
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->item->employee?->full_name ?? 'An employee';
        $kind = ucfirst($this->kind());
        [$title, $message, $icon] = match ($this->event) {
            'submitted' => ["{$kind} to approve", "{$name}: {$this->subject()} · {$this->amount()}", $this->isTravel() ? 'plane' : 'receipt'],
            'awaiting_payment' => ['Expense claim to pay', "{$name}: {$this->subject()} · {$this->amount()}", 'banknote'],
            'rejected' => ["{$kind} rejected", "{$this->subject()}: ".($this->item->decision_note ?: 'no reason given'), 'x-circle'],
            'payroll_pending' => ['Expense claim to be paid with salary', "{$this->subject()} · {$this->amount()} will be added to your next payslip", 'banknote'],
            'paid' => ['Expense claim paid', "{$this->subject()} · {$this->amount()}", 'banknote'],
            default => ["{$kind} approved", "{$this->subject()} · {$this->amount()}", 'check-circle'],
        };

        return ['title' => $title, 'message' => $message, 'url' => $this->url(), 'icon' => $icon];
    }
}
