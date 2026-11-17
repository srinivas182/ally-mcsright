<?php

declare(strict_types=1);

namespace Modules\Team;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Pat mentioned you in Everyone", sent only when the mention is still unread after 10 minutes. */
final class MentionMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $from, public readonly string $where, public readonly string $excerpt, public readonly string $path) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->from} mentioned you in {$this->where}")
            ->line("{$this->from} wrote:")
            ->line('"'.$this->excerpt.'"')
            ->action('Open team chat', url($this->path))
            ->line('You get this email only when a mention is still unread after 10 minutes.');
    }
}
