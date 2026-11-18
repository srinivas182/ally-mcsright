<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** RightAlly support asks to view the brokerage's account (FL1, specs/core/16). */
final class SupportAccessRequested extends Notification
{
    use Queueable;

    public function __construct(public readonly string $byName, public readonly string $reason, public readonly string $link) {}

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
            ->subject('RightAlly support asks to view your account')
            ->line("{$this->byName} from RightAlly support would like to view your account to help with: {$this->reason}")
            ->line('Nothing happens unless you allow it. If you allow it, support can help for the next 24 hours, and every visit is listed for you.')
            ->action('Allow or decline', $this->link);
    }
}
