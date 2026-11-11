<?php

declare(strict_types=1);

namespace Modules\Crm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TaskReminder extends Notification
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly ?string $contactName, public readonly string $dueText, public readonly string $url) {}

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
            ->subject('Reminder: '.$this->title)
            ->line($this->contactName !== null ? "{$this->title} — {$this->contactName}" : $this->title)
            ->line("Due {$this->dueText}.")
            ->action('Open', $this->url);
    }
}
