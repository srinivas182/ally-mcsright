<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The person's copy of their information (TR1): a private link valid for 7 days. */
final class PrivacyCopyMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $brokerage, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your information from '.$this->brokerage)
            ->greeting('Hello '.$this->name.',')
            ->line('Here is a copy of the information '.$this->brokerage.' holds about you, as you asked.')
            ->action('Download my information', $this->link)
            ->line('The link works for 7 days. Do not forward this email.');
    }
}
