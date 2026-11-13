<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InstantRequested extends Notification
{
    use Queueable;

    public function __construct(public readonly string $agent, public readonly int $cents) {}

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
            ->subject("{$this->agent} asked to get paid now")
            ->line("{$this->agent} asked to be paid \$".number_format($this->cents / 100, 2).' today. It is approved and ready in Finance › Payouts.')
            ->line('Send it and upload the same-day bank file to your bank.')
            ->action('Open payouts', url('/finance/payouts'));
    }
}
