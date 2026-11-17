<?php

declare(strict_types=1);

namespace Modules\Team\Help;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** RightAlly support's answer to a help request. */
final class SupportReplyMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $subject, public readonly string $reply) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('RightAlly support: '.$this->subject)
            ->line('Here is our answer to your request "'.$this->subject.'":')
            ->line($this->reply)
            ->line('You can also read it, or ask again, on the Help page in RightAlly. We will never ask for your password or card numbers.');
    }
}
