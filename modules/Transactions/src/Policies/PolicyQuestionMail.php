<?php

declare(strict_types=1);

namespace Modules\Transactions\Policies;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An agent's policy question the documents could not answer (or that needs the broker). */
final class PolicyQuestionMail extends Notification
{
    use Queueable;

    public function __construct(public readonly string $who, public readonly string $question) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->who} has a policy question")->line("{$this->who} asked:")->line('"'.$this->question.'"')
            ->line('Your policy documents do not answer it (or it needs you). Reply from Policies; consider adding the answer to your manual.')
            ->action('Reply', url('/policies'));
    }
}
