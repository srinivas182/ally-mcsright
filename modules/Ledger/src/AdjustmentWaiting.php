<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the people who can approve adjustments that one is waiting for them (FH1). */
final class AdjustmentWaiting extends Notification
{
    use Queueable;

    public function __construct(public readonly string $description, public readonly int $totalCents, public readonly string $by) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('An adjustment to the books needs your approval')
            ->line("{$this->by} entered an adjustment of $".number_format($this->totalCents / 100, 2).": \"{$this->description}\".")
            ->line('It will not change the books until a second person approves it.')
            ->action('Review the adjustment', url('/finance/adjustments'));
    }
}
