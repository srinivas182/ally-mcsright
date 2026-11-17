<?php

declare(strict_types=1);

namespace Modules\Engage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A past client answered a check-in: what they want, in a line, and a suggested reply waiting (EN1, D23). */
final class CheckInReplyMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $who, public readonly string $intent, public readonly string $brief, public readonly string $path) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->who} replied: {$this->intent}")
            ->line($this->brief !== '' ? $this->brief : "{$this->who} answered your check-in email.")
            ->line('A suggested reply is ready for you to read, change and send. A task is on your list for today.')
            ->action('Open the reply', url($this->path));
    }
}
