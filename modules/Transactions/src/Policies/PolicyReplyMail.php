<?php

declare(strict_types=1);

namespace Modules\Transactions\Policies;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The broker answered an agent's policy question. */
final class PolicyReplyMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $question, public readonly string $reply, public readonly string $broker) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your broker answered your policy question')->line('"'.$this->question.'"')->line("{$this->broker}: {$this->reply}")
            ->action('Open Policies', url('/policies'));
    }
}
