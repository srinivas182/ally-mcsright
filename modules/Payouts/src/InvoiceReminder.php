<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoiceReminder extends Notification
{
    use Queueable;

    public function __construct(public readonly string $number, public readonly int $cents, public readonly CarbonImmutable $due, public readonly bool $overdue) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $money = '$'.number_format($this->cents / 100, 2);

        return (new MailMessage)
            ->subject($this->overdue ? "Invoice {$this->number} is overdue" : "Invoice {$this->number} is due soon")
            ->line(($this->overdue ? 'Your invoice is past due' : 'Your invoice is due').": {$money}, due {$this->due->toFormattedDateString()}.")
            ->line('You can pay it online, or it will be taken from your next commission if your brokerage does that.')
            ->action('See what I owe', url('/my-money'));
    }
}
