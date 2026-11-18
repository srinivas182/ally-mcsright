<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Please confirm your privacy request" (TR1): proves the request comes from the owner of the email address. */
final class PrivacyConfirmMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $brokerage, public readonly string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Please confirm your privacy request to '.$this->brokerage)
            ->greeting('Hello '.$this->name.',')
            ->line('We received a privacy request with this email address. To make sure it is really you, please confirm it.')
            ->action('Confirm my request', $this->link)
            ->line('If you did not ask, ignore this email and nothing will happen. The link works for 7 days.');
    }
}
