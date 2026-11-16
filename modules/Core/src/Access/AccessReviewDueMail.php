<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccessReviewDueMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $quarter) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Your {$this->quarter} access review is due")
            ->line('Once a quarter, confirm who can see money, people and contacts in your brokerage. It takes a few minutes: keep or remove each role, then sign it off.')
            ->line('Signed-off reviews are the evidence auditors ask for.')
            ->action('Start the access review', url('/settings/access-review'));
    }
}
