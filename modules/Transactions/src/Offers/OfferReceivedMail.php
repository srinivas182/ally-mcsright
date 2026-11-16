<?php

declare(strict_types=1);

namespace Modules\Transactions\Offers;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An offer came in (or was revised) through the listing's offer link. */
final class OfferReceivedMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $street, public readonly string $label, public readonly string $agent, public readonly int $priceCents, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->label} on {$this->street}: $".number_format($this->priceCents / 100))
            ->line("{$this->agent} sent {$this->label} on {$this->street}.")
            ->action('Compare the offers', url($this->path));
    }
}
