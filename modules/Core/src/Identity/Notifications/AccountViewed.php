<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountViewed extends Notification
{
    use Queueable;

    public function __construct(public readonly string $byName, public readonly string $reason) {}

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
            ->subject('Someone viewed your RightAlly account to help you')
            ->line("{$this->byName} is viewing your account to help with: {$this->reason}")
            ->line('They cannot change your password, security settings, email or payout details. If you did not expect this, tell your broker.');
    }
}
