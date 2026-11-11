<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangeRequested extends Notification
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
            ->subject('Your RightAlly email is being changed')
            ->line("Someone asked to change your sign-in email to {$this->newEmail}. It changes only after that address is confirmed. If this wasn't you, change your password and tell your broker.");
    }
}
