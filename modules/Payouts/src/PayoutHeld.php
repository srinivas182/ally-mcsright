<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A deal's money matched, but its payouts are held by a money or wire-fraud blocker (Deal-IQ). */
final class PayoutHeld extends Notification
{
    use Queueable;

    public function __construct(public readonly string $deal, public readonly string $dealUlid, public readonly string $reason) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Payouts held: {$this->deal}")
            ->line("The money for {$this->deal} arrived, but its payouts are held because: {$this->reason}")
            ->line('Resolve it on the deal (or dismiss the finding with a reason). The automatic payout then goes ahead by itself.')
            ->action('Open the deal', url('/deals/'.$this->dealUlid));
    }
}
