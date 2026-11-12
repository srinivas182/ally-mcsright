<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CdaSent extends Notification
{
    use Queueable;

    public function __construct(public readonly string $brokerage, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Commission disbursement authorisation from {$this->brokerage}")
            ->line("{$this->brokerage} has sent a commission disbursement authorisation. Open it, then acknowledge receipt.")
            ->action('Open the CDA', $this->url)
            ->line('Payment instructions never change by email. If anyone asks you to pay a different account, call the brokerage on a number you already know.');
    }
}
