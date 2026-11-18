<?php

declare(strict_types=1);

namespace Modules\Transactions\DealIq;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** DQ4: someone other than the agent sent mail to the agent's Deal-IQ address; it was ignored. */
final class EmailIntakeRefused extends Notification
{
    use Queueable;

    public function __construct(public readonly string $from) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Deal-IQ ignored an email sent to your address')
            ->line('An email from '.$this->from.' was sent to your Deal-IQ address. Only emails you (or your own assistants) forward are read, so it was ignored.')
            ->line('If it was yours, forward it from the email address you sign in to RightAlly with.');
    }
}
