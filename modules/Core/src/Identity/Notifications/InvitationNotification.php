<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email. Carries the only copy of the raw token.
 */
final class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $tenantName,
        public readonly ?string $inviterName,
        public readonly string $expiresOn,
    ) {}

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
            ->subject("You're invited to join {$this->tenantName} on RightAlly")
            ->greeting('Hello!')
            ->line(($this->inviterName ?? 'Your brokerage')." invited you to join {$this->tenantName} on RightAlly.")
            ->action('Accept invitation', $this->url)
            ->line("This link works once and expires on {$this->expiresOn}.");
    }
}
