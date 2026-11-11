<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ConfirmNewEmail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $url) {}

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
            ->subject('Confirm your new email for RightAlly')
            ->line("Hi {$this->name}, please confirm this is your new sign-in email. The link works for one hour.")->action('Confirm my new email', $this->url);
    }
}
