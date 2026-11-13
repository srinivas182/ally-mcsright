<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An automatic payout that needs a person: over the limit (approve), or approved on the bank file (send). */
final class AutoPayoutWaiting extends Notification
{
    use Queueable;

    public function __construct(public readonly string $deal, public readonly ?string $payee, public readonly int $cents, public readonly int $limitCents, public readonly bool $readyToSend) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $money = fn (int $c): string => '$'.number_format($c / 100, 2);
        $mail = (new MailMessage)->subject($this->readyToSend ? "Payouts for {$this->deal} are ready to send" : "Approve the payout for {$this->deal}");

        return ($this->readyToSend
            ? $mail->line("The money for {$this->deal} matched the CDA, so its payouts ({$money($this->cents)}) were approved under your automatic rule.")->line('Send the run and upload the bank file to your bank.')
            : $mail->line("The money for {$this->deal} matched the CDA. {$this->payee}'s payout of {$money($this->cents)} is over your automatic limit of {$money($this->limitCents)}, so it needs approval."))
            ->action('Open payouts', url('/finance/payouts'));
    }
}
