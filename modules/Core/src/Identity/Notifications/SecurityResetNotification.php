<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SecurityResetNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $byName) {}

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
            ->subject('Your RightAlly sign-in security was reset')
            ->line("{$this->byName} reset your sign-in security: two-step verification and passkeys were removed and you were signed out everywhere.")
            ->line('Use the separate password reset email to choose a new password, then set up two-step verification again.')
            ->line('If you did not ask for this, contact your broker straight away.');
    }
}
