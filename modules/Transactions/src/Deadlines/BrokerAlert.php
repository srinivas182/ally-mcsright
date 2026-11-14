<?php

declare(strict_types=1);

namespace Modules\Transactions\Deadlines;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Escalates to the broker: a key date was missed, or a blocker is still open close to closing. */
final class BrokerAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly string $street, public readonly string $agent, public readonly string $message, public readonly string $dealUlid) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Needs you: {$this->street}")->line("{$this->agent}'s deal at {$this->street}: {$this->message}")
            ->action('Open the deal', url('/deals/'.$this->dealUlid));
    }
}
