<?php

declare(strict_types=1);

namespace Modules\Core\Privacy;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A confirmed privacy request for the people who handle them (TR1). Urgent: the law sets the answer date. */
final class PrivacyRequestMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $kind, public readonly string $due) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Privacy request: '.$this->kind)
            ->line($this->name.' confirmed a privacy request: '.$this->kind.'.')
            ->line('Please answer by '.$this->due.'. RightAlly finds their records and does the work when you press the button.')
            ->action('Open privacy requests', url('/settings/privacy'));
    }
}
