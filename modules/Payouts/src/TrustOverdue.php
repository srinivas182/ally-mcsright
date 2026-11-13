<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TrustOverdue extends Notification
{
    use Queueable;

    public function __construct(public readonly string $month) {}

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
            ->subject("Trust account not yet reconciled for {$this->month}")
            ->line("Your trust account for {$this->month} has not been reconciled and approved yet. Most states require a monthly three-way reconciliation.")
            ->line('Upload the trust bank statement, check that the three balances agree, and have a second person approve it.')
            ->action('Reconcile the trust account', url('/finance/trust'));
    }
}
