<?php

declare(strict_types=1);

namespace Modules\Crm\Portability;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To an agent who left: a private link to download their own people (the portability guarantee). */
final class TakeYourPeopleMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $brokerage, public readonly int $people, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Take your people with you')
            ->greeting('Hello '.$this->name.',')
            ->line('Your account at '.$this->brokerage.' has been closed. The '.number_format($this->people).' people you added yourself are yours to keep.')
            ->action('Download my people', $this->url)
            ->line('The file opens in any spreadsheet program and imports straight into another RightAlly. The link works for 30 days.')
            ->line('People who came from the brokerage\'s own website, ads or lead sources stay with the brokerage.');
    }
}
