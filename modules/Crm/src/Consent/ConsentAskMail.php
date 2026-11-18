<?php

declare(strict_types=1);

namespace Modules\Crm\Consent;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "May we text you?" (CN1): one tap to agree. Sent by the agent, in the brokerage's name. */
final class ConsentAskMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $firstName, public readonly string $agent, public readonly string $brokerage, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('May '.$this->agent.' text you?')
            ->greeting('Hi '.$this->firstName.',')
            ->line($this->agent.' at '.$this->brokerage.' would like to be able to text you about your home search or sale. It takes one tap.')
            ->action('Yes or no, in one tap', $this->link)
            ->line('You can say no, and you can stop texts at any time by replying STOP. This link works for 30 days.');
    }
}
