<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewSignInNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $device, public readonly string $ip, public readonly string $secureUrl) {}

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
            ->subject('New sign-in to your RightAlly account')
            ->line("Someone signed in to your account from {$this->device} (IP {$this->ip}).")
            ->line('If this was you, there is nothing to do.')
            ->action('Not you? Secure my account', $this->secureUrl)
            ->line('Securing your account signs you out everywhere and asks you to set a new password.');
    }
}
