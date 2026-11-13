<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentReturned extends Notification
{
    use Queueable;

    public function __construct(public readonly int $cents, public readonly ?string $reason) {}

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
            ->subject('A payment to you did not go through')
            ->line('Your payment of $'.number_format($this->cents / 100, 2).' could not be completed'.($this->reason !== null ? " ({$this->reason})" : '').'.')
            ->line('The money is back in your balance and nothing is lost. Please check your bank details; your brokerage will pay it in the next run.')
            ->action('Check my bank details', url('/my-money'));
    }
}
