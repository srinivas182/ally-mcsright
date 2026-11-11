<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChanged extends Notification
{
    use Queueable;

    public function __construct(public readonly string $newEmail) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your RightAlly email was changed')
            ->line("Your sign-in email is now {$this->newEmail}. If this wasn't you, contact your broker straight away.");
    }
}
