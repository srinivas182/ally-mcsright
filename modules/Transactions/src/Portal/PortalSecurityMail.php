<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client said a portal sign-in was not them: every device was signed out. */
final class PortalSecurityMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $who, public readonly string $street, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Security: {$this->who} reported a sign-in on {$this->street}")
            ->line("{$this->who} said a sign-in to their deal portal was not them. Every device was signed out; nobody can get back in without a new link and a code sent to their email.")
            ->line('Call them on a number you already have to check, and warn them about wire fraud.')
            ->action('Open the deal', url($this->path));
    }
}
