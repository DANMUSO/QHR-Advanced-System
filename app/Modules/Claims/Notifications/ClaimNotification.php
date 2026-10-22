<?php

namespace App\Modules\Claims\Notifications;

use App\Modules\Claims\Models\MedicalClaim;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification per workflow step (HR-FEATURES §11):
 *   submitted         → medical approver        mail + bell
 *   approved          → claimant                mail + bell ("proceed to Finance")
 *   awaiting_payment  → finance person          mail + bell (amount + claimant phone)
 *   hr_rejected       → claimant                mail + SMS + bell
 *   paid              → claimant                mail + bell
 *   finance_rejected  → claimant                mail + SMS + bell
 */
class ClaimNotification extends Notification
{
    use Queueable;

    public function __construct(public MedicalClaim $claim, public string $event) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];
        if (in_array($this->event, ['hr_rejected', 'finance_rejected'], true)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    private function money(?float $v = null): string
    {
        return 'KES '.number_format($v ?? $this->claim->effectiveAmount(), 2);
    }

    private function url(): string
    {
        return match ($this->event) {
            'submitted' => '/claims/approvals',
            'awaiting_payment' => '/claims/payments',
            default => '/claims', // the claimant's My Claims page (there is no per-claim SPA route)
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->claim;
        $name = $c->employee?->full_name ?? 'Employee';
        $mail = (new MailMessage)->greeting('Hello '.($notifiable->name ?? '').',');

        return match ($this->event) {
            'submitted' => $mail->subject("Medical claim {$c->reference} awaiting your approval")
                ->line("{$name} submitted a medical claim of {$this->money((float) $c->total_amount)} for {$c->claimant_name} ({$c->provider_name}, {$c->claim_date->format('d M Y')}).")
                ->action('Review claim', url($this->url())),
            'approved' => $mail->subject("Medical claim {$c->reference} approved")
                ->line("Your medical claim {$c->reference} has been approved for {$this->money()}.")
                ->when($c->approved_amount < $c->total_amount, fn ($m) => $m->line("Note from HR: {$c->hr_comment}"))
                ->line('It has been forwarded to Finance for payment — please proceed to Finance.')
                ->action('View claim', url($this->url())),
            'awaiting_payment' => $mail->subject("Medical claim {$c->reference} ready for payment")
                ->line("HR approved a medical claim for {$name}.")
                ->line("Amount: {$this->money()}")
                ->line('Phone (M-Pesa): '.($c->employee?->phone ?: 'not on file'))
                ->action('Open payments queue', url($this->url())),
            'hr_rejected' => $mail->subject("Medical claim {$c->reference} rejected")
                ->line("Your medical claim {$c->reference} of {$this->money((float) $c->total_amount)} was rejected by HR.")
                ->line("Reason: {$c->hr_comment}")
                ->action('View claim', url($this->url())),
            'paid' => $mail->subject("Medical claim {$c->reference} paid")
                ->line("Your medical claim {$c->reference} has been paid: {$this->money()} via ".(MedicalClaim::PAYMENT_METHODS[$c->payment_method] ?? $c->payment_method)
                    .($c->payment_reference ? " (ref {$c->payment_reference})" : '').'.')
                ->action('Download payment confirmation', url("/api/claims/{$c->id}/confirmation")),
            'finance_rejected' => $mail->subject("Medical claim {$c->reference} rejected by Finance")
                ->line("Finance could not pay your medical claim {$c->reference} ({$this->money()}).")
                ->line("Reason: {$c->finance_comment}")
                ->line('The amount has been restored to your medical balance.')
                ->action('View claim', url($this->url())),
            default => $mail->subject("Medical claim {$c->reference} updated")->action('View claim', url($this->url())),
        };
    }

    public function toSms(object $notifiable): string
    {
        $c = $this->claim;

        return $this->event === 'hr_rejected'
            ? "Your medical claim {$c->reference} of {$this->money((float) $c->total_amount)} was rejected by HR. Reason: {$c->hr_comment}"
            : "Your medical claim {$c->reference} ({$this->money()}) was rejected by Finance. Reason: {$c->finance_comment}. Your balance has been restored.";
    }

    public function toArray(object $notifiable): array
    {
        $c = $this->claim;
        $name = $c->employee?->full_name ?? 'An employee';
        [$title, $message, $icon] = match ($this->event) {
            'submitted' => ['Medical claim to approve', "{$name} claimed {$this->money((float) $c->total_amount)} ({$c->reference})", 'heart-pulse'],
            'approved' => ['Medical claim approved', "{$c->reference} approved for {$this->money()} — proceed to Finance", 'check-circle'],
            'awaiting_payment' => ['Medical claim to pay', "{$name}: {$this->money()} · ".($c->employee?->phone ?: 'no phone'), 'banknote'],
            'hr_rejected' => ['Medical claim rejected', "{$c->reference}: {$c->hr_comment}", 'x-circle'],
            'paid' => ['Medical claim paid', "{$c->reference}: {$this->money()} paid", 'banknote'],
            'finance_rejected' => ['Medical claim rejected by Finance', "{$c->reference}: {$c->finance_comment}", 'x-circle'],
            default => ['Medical claim updated', $c->reference, 'heart-pulse'],
        };

        return ['title' => $title, 'message' => $message, 'url' => $this->url(), 'icon' => $icon, 'claim_id' => $c->id];
    }
}
