<?php

declare(strict_types=1);

namespace Modules\Sites\Demand;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To an agent whose buyers match a home about to be listed (specs/grow/10): how many of their buyers, and whom to call. */
final class DemandAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly string $address, public readonly int $price, public readonly int $buyers, public readonly string $listingAgent,
        public readonly ?string $listingAgentEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Coming soon: '.$this->address.' may suit '.$this->buyers.' of your buyers')
            ->line($this->listingAgent.' expects to list '.$this->address.' at about $'.number_format($this->price).'. It matches the saved searches or wishes of '
                .$this->buyers.' of your buyers.')
            ->line('Ask '.$this->listingAgent.($this->listingAgentEmail !== null ? ' ('.$this->listingAgentEmail.')' : '').' about a preview before it goes on the market.')
            ->action('See your contacts', url('/contacts'));
    }
}
