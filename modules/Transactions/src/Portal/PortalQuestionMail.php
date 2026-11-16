<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client's question the concierge passed to the agent (money, wiring, legal, or unknown). */
final class PortalQuestionMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $who, public readonly string $street, public readonly string $question, public readonly string $path) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->who} asked about {$this->street}")
            ->line("{$this->who} asked in the deal portal:")
            ->line('"'.$this->question.'"')
            ->line('Reply from the deal page; they get your answer by email in their language setting.')
            ->action('Reply', url($this->path));
    }
}
